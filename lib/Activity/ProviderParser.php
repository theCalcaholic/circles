<?php

/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright 2017
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Circles\Activity;

use OCA\Circles\Api\v1\Circles;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\FederatedLink;
use OCA\Circles\Model\Member;
use OCA\Circles\Service\MiscService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IL10N;
use OCP\IURLGenerator;

class ProviderParser {


	/** @var MiscService */
	protected $miscService;

	/** @var IL10N */
	protected $l10n;

	/** @var IURLGenerator */
	protected $url;

	/** @var IManager */
	protected $activityManager;

	public function __construct(
		IURLGenerator $url, IManager $activityManager, IL10N $l10n, MiscService $miscService
	) {
		$this->url = $url;
		$this->activityManager = $activityManager;
		$this->l10n = $l10n;
		$this->miscService = $miscService;
	}


	/**
	 * general function to generate Circle event.
	 *
	 * @param IEvent $event
	 * @param Circle $circle
	 * @param FederatedLink|null $remote
	 * @param string $ownEvent
	 * @param string $othersEvent
	 */
	protected function parseCircleEvent(IEvent $event, Circle $circle, $remote, $ownEvent, $othersEvent
	) {
		$data = [
			'author' => $this->generateViewerParameter($circle),
			'circle' => $this->generateCircleParameter($circle)
		];

		$remoteCircle = $this->generateRemoteCircleParameter($remote);
		if ($remoteCircle !== null) {
			$data['remote'] = $remoteCircle;
		}

		if ($this->isViewerTheAuthor($circle, $this->activityManager->getCurrentUserId())) {
			$this->setSubject($event, $ownEvent, $data);

			return;
		}

		$this->setSubject($event, $othersEvent, $data);
	}


	/**
	 * @param IEvent $event
	 * @param string $line
	 * @param array $data
	 */
	protected function setSubject(IEvent $event, $line, $data) {
		$this->setParsedSubject($event, $line, $data);
		$this->setRichSubject($event, $line, $data);
	}


	/**
	 * @param IEvent $event
	 * @param string $line
	 * @param array $data
	 */
	protected function setRichSubject(IEvent $event, $line, $data) {
		$ak = array_keys($data);
		foreach ($ak as $k) {
			$subAk = array_keys($data[$k]);
			foreach ($subAk as $sK) {
				if (substr($sK, 0, 1) === '_') {
					unset($data[$k][$sK]);
				}
			}
		}

		$event->setRichSubject($line, $data);
	}


	/**
	 * @param IEvent $event
	 * @param string $line
	 * @param array $data
	 */
	protected function setParsedSubject(IEvent $event, $line, $data) {
		$ak = array_keys($data);
		$replace = [];
		foreach ($ak as $k) {
			if (!key_exists('_parsed', $data[$k])) {
				continue;
			}

			$replace['{' . $k . '}'] = $data[$k]['_parsed'];
		}

		$line = strtr($line, $replace);

		$event->setParsedSubject($line);
	}


	/**
	 * general function to generate Member event.
	 *
	 * @param Circle $circle
	 * @param $member
	 * @param IEvent $event
	 * @param $ownEvent
	 * @param $othersEvent
	 */
	protected function parseMemberEvent(
		IEvent $event, Circle $circle, Member $member, $ownEvent, $othersEvent
	) {
		$data = [
			'circle' => $this->generateCircleParameter($circle),
			'member' => $this->generateUserParameter($member)
		];

		if ($member->getUserId() === $this->activityManager->getCurrentUserId()
		) {
			$this->setSubject($event, $ownEvent, $data);

			return;
		}

		$this->setSubject($event, $othersEvent, $data);
	}


	/**
	 * general function to generate Link event.
	 *
	 * @param Circle $circle
	 * @param FederatedLink $remote
	 * @param IEvent $event
	 * @param string $line
	 */
	protected function parseLinkEvent(IEvent $event, Circle $circle, FederatedLink $remote, $line
	) {
		$data = [
			'circle' => $this->generateCircleParameter($circle),
			'remote' => $this->generateRemoteCircleParameter($remote)
		];

		$this->setSubject($event, $line, $data);
	}


	/**
	 * general function to generate Circle+Member event.
	 *
	 * @param Circle $circle
	 * @param Member $member
	 * @param IEvent $event
	 * @param string $ownEvent
	 * @param string $othersEvent
	 */
	protected function parseCircleMemberEvent(
		IEvent $event, Circle $circle, Member $member, $ownEvent, $othersEvent
	) {
		$data = [
			'author'   => $this->generateViewerParameter($circle),
			'circle'   => $this->generateCircleParameter($circle),
			'member'   => $this->generateUserParameter($member),
			'external' => $this->generateExternalMemberParameter($member),
			'group'    => $this->generateGroupParameter($member),
		];

		if ($this->isViewerTheAuthor($circle, $this->activityManager->getCurrentUserId())) {
			$this->setSubject($event, $ownEvent, $data);

			return;
		}

		$this->setSubject($event, $othersEvent, $data);
	}


	/**
	 * general function to generate Circle+Member advanced event.
	 *
	 * @param Circle $circle
	 * @param Member $member
	 * @param IEvent $event
	 * @param $ownEvent
	 * @param $targetEvent
	 * @param $othersEvent
	 */
	protected function parseCircleMemberAdvancedEvent(
		IEvent $event, Circle $circle, Member $member, $ownEvent, $targetEvent, $othersEvent
	) {
		$data = [
			'author' => $this->generateViewerParameter($circle),
			'circle' => $this->generateCircleParameter($circle),
			'member' => $this->generateUserParameter($member)
		];

		if ($this->isViewerTheAuthor($circle, $this->activityManager->getCurrentUserId())) {
			$this->setSubject($event, $ownEvent, $data);

			return;
		}

		if ($member->getUserId() === $this->activityManager->getCurrentUserId()) {
			$this->setSubject($event, $targetEvent, $data);

			return;
		}

		$this->setSubject($event, $othersEvent, $data);
	}


	/**
	 * @param Circle $circle
	 * @param string $userId
	 *
	 * @return bool
	 */
	protected function isViewerTheAuthor(Circle $circle, $userId) {
		if ($circle->getViewer() === null) {
			return false;
		}

		if ($circle->getViewer()
				   ->getUserId() === $userId) {
			return true;
		}

		return false;
	}


	/**
	 * @param Circle $circle
	 *
	 * @return string|array <string,string|integer>
	 */
	protected function generateViewerParameter(Circle $circle) {
		if ($circle->getViewer() === null) {
			return [];
		}

		return $this->generateUserParameter($circle->getViewer());
	}


	/**
	 * @param Member $member
	 *
	 * @return array|string <string,string|integer>
	 */
	protected function generateExternalMemberParameter(Member $member) {
		return [
			'type'    => $member->getTypeName(),
			'id'      => $member->getUserId(),
			'name'    => $member->getDisplayName() . ' (' . $member->getTypeString() . ')',
			'_parsed' => $member->getDisplayName()
		];
	}


	/**
	 * @param Circle $circle
	 *
	 * @return array<string,string|integer>
	 */
	protected function generateCircleParameter(Circle $circle) {
		return [
			'type'    => 'circle',
			'id'      => $circle->getId(),
			'name'    => $circle->getName(),
			'_parsed' => $circle->getName(),
			'link'    => Circles::generateAbsoluteLink($circle->getUniqueId())
		];
	}


	/**
	 * @param FederatedLink $link
	 *
	 * @return array<string,string|integer>
	 */
	protected function generateRemoteCircleParameter($link) {

		if ($link === null) {
			return null;
		}

		return [
			'type'    => 'circle',
			'id'      => $link->getUniqueId(),
			'name'    => $link->getToken() . '@' . $link->getAddress(),
			'_parsed' => $link->getToken() . '@' . $link->getAddress()
		];
	}


	/**
	 * @param Member $member
	 *
	 * @return array <string,string|integer>
	 */
	protected function generateUserParameter(Member $member) {
		return [
			'type'    => 'user',
			'id'      => $member->getUserId(),
			'name'    => $this->miscService->getDisplayName($member->getUserId(), true),
			'_parsed' => $this->miscService->getDisplayName($member->getUserId(), true)
		];
	}


	/**
	 * @param Member $group
	 *
	 * @return array <string,string|integer>
	 */
	protected function generateGroupParameter($group) {
		return [
			'type'    => 'user-group',
			'id'      => $group->getUserId(),
			'name'    => $group->getUserId(),
			'_parsed' => $group->getUserId()
		];
	}

}
