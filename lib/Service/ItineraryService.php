<?php declare(strict_types=1);

/**
 * @copyright 2019 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2019 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
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
 */

namespace OCA\Mail\Service;

use ChristophWurst\KItinerary\Itinerary;
use OCA\Mail\Account;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\IMAP\MessageMapper;
use OCA\Mail\Integration\KItinerary\ItineraryExtractor;
use OCP\ICacheFactory;
use function json_encode;

class ItineraryService {

	/** @var IMAPClientFactory */
	private $clientFactory;

	/** @var MessageMapper */
	private $messageMapper;

	/** @var ItineraryExtractor */
	private $extractor;

	public function __construct(IMAPClientFactory $clientFactory,
								MessageMapper $messageMapper,
								ItineraryExtractor $extractor,
								ICacheFactory $cacheFactory) {
		$this->clientFactory = $clientFactory;
		$this->messageMapper = $messageMapper;
		$this->extractor = $extractor;
		$this->cache = $cacheFactory->createLocal();
	}

	public function extract(Account $account, string $mailbox, int $id): Itinerary {
		$cacheKey = 'mail_itinerary_' . $account->getId() . '_' . $mailbox . '_' . $id;
		if ($cached = ($this->cache->get($cacheKey))) {
			return Itinerary::fromJson($cached);
		}

		$client = $this->clientFactory->getClient($account);

		$itinerary = new Itinerary();
		$htmlBody = $this->messageMapper->getHtmlBody($client, $mailbox, $id);
		if ($htmlBody !== null) {
			$itinerary = $itinerary->merge(
				$this->extractor->extract($htmlBody)
			);
		}
		$attachments = $this->messageMapper->getRawAttachments($client, $mailbox, $id);
		foreach ($attachments as $attachment) {
			$itinerary = $itinerary->merge(
				$this->extractor->extract($attachment)
			);
		}

		// Lastly, we put the extracted data through the tool again, so it can combine
		// and pick the most relevant information
		$final = $this->extractor->extract(json_encode($itinerary));

		$this->cache->set($cacheKey, json_encode($final));

		return $final;
	}

}
