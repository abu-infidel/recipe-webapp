<?php
declare(strict_types=1);

namespace App\Core\Template;

/**
 * A template could not be parsed or rendered. The message names the template
 * and the line, because the person fixing it may be a model that has only the
 * theme folder and this message to go on.
 */
final class TemplateError extends \RuntimeException {}
