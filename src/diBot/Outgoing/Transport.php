<?php
namespace diBot\Outgoing;
enum Transport: string
{
    case Url = 'url';
    case Upload = 'upload';
    case Refuse = 'refuse';
}
