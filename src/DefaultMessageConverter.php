<?php
/**
 * This file is part of the prooph/mongodb-event-store.
 * (c) 2018 prooph software GmbH <contact@prooph.de>
 * (c) 2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStore\MongoDb;

use Prooph\Common\Messaging\Message;
use Prooph\Common\Messaging\MessageConverter;

class DefaultMessageConverter implements MessageConverter
{
    public function convertToArray(Message $message): array
    {
        return [
           'message_name' => $message->messageName(),
           'uuid' => $message->uuid()->toString(),
           'payload' => $message->payload(),
           'metadata' => $message->metadata(),
           'created_at' => $message->createdAt(),
        ];
    }
}
