<?php
namespace diBot;

use diBot\Http\Client;
use Psr\Log\LoggerInterface;

enum Platform: int
{
    case Telegram = 1;
    case Max = 2;
    case Instagram = 3;

    public function api(
        Config $config,
        ?Client $http = null,
        ?LoggerInterface $logger = null
    ): AbstractBotApi {
        return match ($this) {
            self::Telegram => new Telegram\BotApi($config, $http, $logger),
            self::Max => new Max\BotApi($config, $http, $logger),
            self::Instagram => throw new \LogicException('Instagram is not implemented'),
        };
    }

    public function parse(array $data): ?AbstractUpdate
    {
        return match ($this) {
            self::Telegram => Telegram\Update::fromArray($data),
            self::Max => Max\Update::fromArray($data),
            self::Instagram => null,
        };
    }
}
