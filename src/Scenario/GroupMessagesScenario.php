<?php

declare(strict_types=1);

namespace TelegramOSINT\Scenario;

use TelegramOSINT\Client\InfoObtainingClient\Models\MessageModel;
use TelegramOSINT\Exception\TGException;
use TelegramOSINT\Logger\Logger;
use TelegramOSINT\MTSerialization\AnonymousMessage;
use TelegramOSINT\Scenario\Models\GroupId;
use TelegramOSINT\Scenario\Models\OptionalDateRange;

class GroupMessagesScenario extends InfoClientScenario
{
    /** @var callable|null */
    private $handler;

    /** @var string|null */
    private $username;
    /** @var int|null */
    private $userId;
    /** @var int|null */
    private $startTimestamp;
    /** @var int|null */
    private $endTimestamp;
    /** @var GroupId */
    private $groupIdObj;
    /** @var ClientGeneratorInterface */
    private $generator;
    /** @var int */
    private $callLimit;

    /**
     * @param GroupId                  $groupId
     * @param ClientGeneratorInterface $generator
     * @param OptionalDateRange        $dateRange
     * @param callable|null            $handler   function(MessageModel $message)
     * @param string|null              $username
     * @param int|null                 $callLimit
     *
     * @throws TGException
     */
    public function __construct(
        GroupId $groupId,
        ClientGeneratorInterface $generator,
        OptionalDateRange $dateRange,
        callable $handler = null,
        ?string $username = null,
        ?int $callLimit = 100
    ) {
        parent::__construct($generator);
        $this->handler = $handler;
        $this->startTimestamp = $dateRange->getSince();
        $this->endTimestamp = $dateRange->getTo();
        $this->username = $username;
        $this->groupIdObj = $groupId;
        $this->generator = $generator;
        $this->callLimit = $callLimit;
    }

    /**
     * @param callable $cb function()
     *
     * @return callable function(AnonymousMessage $msg)
     */
    private function getUserResolveHandler(callable $cb): callable
    {
        return function (AnonymousMessage $message) use ($cb) {
            if ($message->getType() === 'contacts.resolvedPeer' && $message->getValue('users')) {
                $user = $message->getValue('users')[0];
                if ($user['_'] == 'user') {
                    $this->userId = (int) $user['id'];
                    Logger::log(__CLASS__, "resolved user {$this->username} to {$this->userId}");
                }
            }
            $cb();
        };
    }

    /**
     * @param bool $pollAndTerminate
     *
     * @throws TGException
     */
    public function startActions(bool $pollAndTerminate = true): void
    {
        $this->login();
        usleep(10000);
        $limit = 100;
        if ($this->username) {
            $this->infoClient->resolveUsername($this->username, $this->getUserResolveHandler(function () use ($limit) {
                $this->parseMessages($this->groupIdObj->getGroupId(), $this->groupIdObj->getAccessHash(), $limit);
            }));
        } else {
            $this->parseMessages($this->groupIdObj->getGroupId(), $this->groupIdObj->getAccessHash(), $limit);
        }

        if ($pollAndTerminate) {
            $this->pollAndTerminate();
        }
    }

    /**
     * @param bool $pollAndTerminate
     *
     * @throws TGException
     */
    public function startLinkParse(bool $pollAndTerminate = true): void
    {
        $this->login();
        usleep(10000);
        $limit = 100;

        if ($this->username) {
            $this->infoClient->resolveUsername($this->username, $this->getUserResolveHandler(function () use ($limit) {
                $this->parseLinks($this->groupIdObj->getGroupId(), $this->groupIdObj->getAccessHash(), $limit);
            }));
        } else {
            $this->parseLinks($this->groupIdObj->getGroupId(), $this->groupIdObj->getAccessHash(), $limit);
        }

        if ($pollAndTerminate) {
            $this->pollAndTerminate();
        }
    }

    private function parseLinks(int $id, int $accessHash, int $limit): void
    {
        $this->infoClient->getChannelLinks($id, $limit, $accessHash, null, null, $this->makeMessagesHandler($id, $accessHash, $limit));
    }

    private function parseMessages(int $id, int $accessHash, int $limit): void
    {
        $this->infoClient->getChannelMessages(
            $id,
            $accessHash,
            $limit,
            null,
            null,
            $this->makeMessagesHandler($id, $accessHash, $limit)
        );
    }

    /**
     * @param int $id
     * @param int $accessHash
     * @param int $limit
     *
     * @return callable function(AnonymousMessage $message)
     */
    private function makeMessagesHandler(int $id, int $accessHash, int $limit): callable
    {
        return function (AnonymousMessage $anonymousMessage) use ($id, $accessHash, $limit) {
            if ($anonymousMessage->getType() != 'messages.channelMessages') {
                Logger::log(__CLASS__, "incorrect message type {$anonymousMessage->getType()}");

                return;
            }

            $messages = $anonymousMessage->getValue('messages');
            /** @var int|null $lastId */
            $lastId = null;
            $bunchSkipped = false;
            foreach ($messages as $message) {
                $lastId = (int) $message['id'];
                if ($message['_'] !== 'message') {
                    continue;
                }
                if (!$message['message']) {
                    continue;
                }
                if ($this->userId && $message['from_id'] != $this->userId) {
                    continue;
                }
                if ($this->endTimestamp && $message['date'] > $this->endTimestamp) {
                    if (!$bunchSkipped) {
                        Logger::log(__CLASS__, "skipping bunch due to later date ({$message['date']} > {$this->endTimestamp})");
                        $bunchSkipped = true;
                    }
                    continue;
                }

                if ($this->startTimestamp && $message['date'] < $this->startTimestamp) {
                    Logger::log(__CLASS__, 'skipping msg due to earlier date');

                    return;
                }

                $body = $message['message'];
                $body = str_replace("\n", ' \\\\ ', $body);
                Logger::log(__CLASS__, "got message '{$body}' from {$message['from_id']} at ".date('Y-m-d H:i:s', $message['date']));
                if ($this->handler) {
                    $handler = $this->handler;
                    $msgModel = new MessageModel(
                        (int) $message['id'],
                        $message['message'],
                        (int) $message['from_id'],
                        (int) $message['date']
                    );
                    $handler($msgModel, $message);
                }

            }

            if ($messages && $lastId !== 1) {
                $this->callLimit--;
                if (!$this->callLimit) {
                    Logger::log(__CLASS__, 'not loading more messages, max call count reached');

                    return;
                }
                Logger::log(__CLASS__, "loading more messages, starting with $lastId");
                usleep(500000);
                $this->infoClient->getChannelMessages(
                    $id,
                    $accessHash,
                    $limit,
                    null,
                    $lastId,
                    $this->makeMessagesHandler($id, $accessHash, $limit)
                );
            }
        };
    }
}
