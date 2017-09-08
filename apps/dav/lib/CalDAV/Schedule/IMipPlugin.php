<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @copyright Copyright (c) 2017, Georg Ehrke
 *
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Georg Ehrke <oc.list@georgehrke.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\CalDAV\Schedule;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\ILogger;
use OCP\L10N\IFactory as L10NFactory;
use OCP\Mail\IMailer;
use Sabre\CalDAV\Schedule\IMipPlugin as SabreIMipPlugin;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\ITip;
use Sabre\VObject\Parameter;
use Sabre\VObject\Recur\EventIterator;
use Swift_Attachment;
/**
 * iMIP handler.
 *
 * This class is responsible for sending out iMIP messages. iMIP is the
 * email-based transport for iTIP. iTIP deals with scheduling operations for
 * iCalendar objects.
 *
 * If you want to customize the email that gets sent out, you can do so by
 * extending this class and overriding the sendMessage method.
 *
 * @copyright Copyright (C) 2007-2015 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class IMipPlugin extends SabreIMipPlugin {

	/** @var string */
	private $appName;

	/** @var string */
	private $userId;

	/** @var IConfig */
	private $config;

	/** @var IMailer */
	private $mailer;

	/** @var ILogger */
	private $logger;

	/** @var ITimeFactory */
	private $timeFactory;

	/** @var L10NFactory */
	private $l10nFactory;

	const MAX_DATE = '2038-01-01';

	/**
	 * Creates the email handler.
	 *
	 * @param string $appName
	 * @param string $userId
	 * @param IConfig $config
	 * @param IMailer $mailer
	 * @param ILogger $logger
	 * @param ITimeFactory $timeFactory
	 * @param L10NFactory $l10nFactory
	 */
	function __construct($appName, $userId, IConfig $config, IMailer $mailer, ILogger $logger, ITimeFactory $timeFactory, L10NFactory $l10nFactory) {
		parent::__construct('');
		$this->appName = $appName;
		$this->userId = $userId;
		$this->config = $config;
		$this->mailer = $mailer;
		$this->logger = $logger;
		$this->timeFactory = $timeFactory;
		$this->l10nFactory = $l10nFactory;
	}

	/**
	 * Event handler for the 'schedule' event.
	 *
	 * @param ITip\Message $iTipMessage
	 * @return void
	 */
	function schedule(ITip\Message $iTipMessage) {

		// Not sending any emails if the system considers the update
		// insignificant.
		if (!$iTipMessage->significantChange) {
			if (!$iTipMessage->scheduleStatus) {
				$iTipMessage->scheduleStatus = '1.0;We got the message, but it\'s not significant enough to warrant an email';
			}
			return;
		}

		$summary = $iTipMessage->message->VEVENT->SUMMARY;

		if (parse_url($iTipMessage->sender, PHP_URL_SCHEME) !== 'mailto') {
			return;
		}

		if (parse_url($iTipMessage->recipient, PHP_URL_SCHEME) !== 'mailto') {
			return;
		}

		// don't send out mails for events that already took place
		if ($this->isEventInThePast($iTipMessage->message)) {
			return;
		}

		$sender = substr($iTipMessage->sender, 7);
		$recipient = substr($iTipMessage->recipient, 7);

		$senderName = ($iTipMessage->senderName) ? $iTipMessage->senderName : null;
		$recipientName = ($iTipMessage->recipientName) ? $iTipMessage->recipientName : null;

		$subject = 'SabreDAV iTIP message';
		switch (strtoupper($iTipMessage->method)) {
			default: // Treat 'REQUEST' as the default
			case 'REQUEST' :
				$subject = $summary;
				$templateName = 'request';
				break;
			case 'REPLY' :
				$subject = 'Re: ' . $summary;
				$templateName = 'reply';
				break;
			case 'CANCEL' :
				$subject = 'Cancelled: ' . $summary;
				$templateName = 'cancel';
				break;
		}

		$vevent = $iTipMessage->message->VEVENT;

		$attendee = $this->getCurrentAttendee($iTipMessage);
		$defaultLang = $this->config->getUserValue($this->userId, 'core', 'lang', $this->l10nFactory->findLanguage());
		$lang = $this->getAttendeeLangOrDefault($attendee, $defaultLang);
		$l10n = $this->l10nFactory->get($this->appName, $lang);

		$meetingAttendeeName = !empty($recipientName) ? $recipientName : $recipient;
		$meetingInviteeName = !empty($senderName) ? $senderName : $sender;

		$meetingTitle = $vevent->DESCRIPTION;
		$meetingDescription = $vevent->SUMMARY;

		// TODO(leon): Maybe it's a good idea to make this locale dependent?
		// TODO(leon): Don't show H:i if it's an all-day meeting
		$dateFormatStr = 'Y-m-d H:i e';
		$meetingStart = $vevent->DTSTART->getDateTime()->format($dateFormatStr);
		$meetingEnd = $vevent->DTEND->getDateTime()->format($dateFormatStr);

		$meetingUrl = $vevent->URL;

		$defaultVal = '--';
		$templateParams = array(
			'l' => $l10n,
			'attendee_name' => $this->stringOrDefault($meetingAttendeeName, $defaultVal),
			'invitee_name' => $this->stringOrDefault($meetingInviteeName, $defaultVal),
			'meeting_title' => $this->stringOrDefault($meetingTitle, $defaultVal),
			'meeting_description' => $this->stringOrDefault($meetingDescription, $defaultVal),
			'meeting_start' => $meetingStart,
			'meeting_end' => $meetingStart,
			'meeting_url' => $this->stringOrDefault($meetingUrl, $defaultVal),
		);
		list($plainBody) = $this->renderMailTemplates($templateName, $templateParams);

		$message = $this->mailer->createMessage()
			->setReplyTo([$sender => $senderName])
			->setTo([$recipient => $recipientName])
			->setSubject($subject)
			->setPlainBody($plainBody)
		;
		// We need to attach the event as 'attachment'
		// Swiftmail can't properly handle inline-multipart-based files
		// See https://github.com/swiftmailer/swiftmailer/issues/615
		$filename = 'event.ics'; // TODO(leon): Make file name unique, e.g. add event id
		$contentType = 'text/calendar; method=' . $iTipMessage->method;
		$attachment = Swift_Attachment::newInstance()
			->setFilename($filename)
			->setContentType($contentType)
			->setBody($iTipMessage->message->serialize())
		;
		$message->getSwiftMessage()->attach($attachment);

		try {
			$failed = $this->mailer->send($message);
			if ($failed) {
				$this->logger->error('Unable to deliver message to {failed}', ['app' => 'dav', 'failed' =>  implode(', ', $failed)]);
				$iTipMessage->scheduleStatus = '5.0; EMail delivery failed';
			}
			$iTipMessage->scheduleStatus = '1.1; Scheduling message is sent via iMip';
		} catch(\Exception $ex) {
			$this->logger->logException($ex, ['app' => 'dav']);
			$iTipMessage->scheduleStatus = '5.0; EMail delivery failed';
		}
	}

	/**
	 * check if event took place in the past already
	 * @param VCalendar $vObject
	 * @return bool
	 */
	private function isEventInThePast(VCalendar $vObject) {
		$component = $vObject->VEVENT;

		$firstOccurrence = $component->DTSTART->getDateTime()->getTimeStamp();
		// Finding the last occurrence is a bit harder
		if (!isset($component->RRULE)) {
			if (isset($component->DTEND)) {
				$lastOccurrence = $component->DTEND->getDateTime()->getTimeStamp();
			} elseif (isset($component->DURATION)) {
				$endDate = clone $component->DTSTART->getDateTime();
				// $component->DTEND->getDateTime() returns DateTimeImmutable
				$endDate = $endDate->add(DateTimeParser::parse($component->DURATION->getValue()));
				$lastOccurrence = $endDate->getTimeStamp();
			} elseif (!$component->DTSTART->hasTime()) {
				$endDate = clone $component->DTSTART->getDateTime();
				// $component->DTSTART->getDateTime() returns DateTimeImmutable
				$endDate = $endDate->modify('+1 day');
				$lastOccurrence = $endDate->getTimeStamp();
			} else {
				$lastOccurrence = $firstOccurrence;
			}
		} else {
			$it = new EventIterator($vObject, (string)$component->UID);
			$maxDate = new \DateTime(self::MAX_DATE);
			if ($it->isInfinite()) {
				$lastOccurrence = $maxDate->getTimestamp();
			} else {
				$end = $it->getDtEnd();
				while($it->valid() && $end < $maxDate) {
					$end = $it->getDtEnd();
					$it->next();

				}
				$lastOccurrence = $end->getTimestamp();
			}
		}

		$currentTime = $this->timeFactory->getTime();
		return $lastOccurrence < $currentTime;
	}

	private function renderMailTemplates($name, $params) {
		$tmplBase = 'mail/' . $name;
		$plainTemplate = new TemplateResponse($this->appName, $tmplBase . '-plain', $params, 'blank');
		return array(
			$plainTemplate->render(),
		);
	}

	private function getCurrentAttendee($iTipMessage) {
		$vevent = $iTipMessage->message->VEVENT;
		$attendees = $vevent->select('ATTENDEE');
		foreach ($attendees as $attendee) {
			if (strcasecmp($attendee->getValue(), $iTipMessage->recipient) === 0) {
				return $attendee;
			}
		}
		return null;
	}

	private function stringOrDefault($str, $def) {
		return !empty($str) ? $str : $def;
	}

	private function getAttendeeLangOrDefault($attendee, $default) {
		if ($attendee) {
			$lang = $attendee->offsetGet('LANGUAGE');
			if ($lang instanceof Parameter) {
				return $lang->getValue();
			}
		}
		return $default;
	}

}
