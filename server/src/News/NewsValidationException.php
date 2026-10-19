<?php
declare(strict_types=1);

namespace MuchoCore\News;

/** Messages suitable for the owner editor; database/runtime errors stay private. */
final class NewsValidationException extends \RuntimeException {}
