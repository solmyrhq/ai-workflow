<?php

declare(strict_types=1);

namespace AiWorkflow\Messages;

enum AttachmentKind: string
{
    case Image = 'image';
    case Document = 'document';
    case Audio = 'audio';
    case Video = 'video';
    case Media = 'media';
    case Text = 'text';
}
