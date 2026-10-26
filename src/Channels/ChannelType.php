<?php

declare(strict_types=1);

namespace Dskripchenko\Realtime\Channels;

enum ChannelType: string
{
    case Public = 'public';
    case Private = 'private';
    case Presence = 'presence';
}
