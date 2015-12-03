<?php

namespace Kelunik\AcmeClient;

use Bramus\Ansi\ControlSequences\EscapeSequences\Enums\SGR;
use Bramus\Monolog\Formatter\ColorSchemes\ColorSchemeInterface;
use Bramus\Monolog\Formatter\ColorSchemes\ColorSchemeTrait;
use Monolog\Logger;

class LoggerColorScheme implements ColorSchemeInterface {
    use ColorSchemeTrait {
        ColorSchemeTrait::__construct as private __constructTrait;
    }

    public function __construct() {
        $this->__constructTrait();

        $this->setColorizeArray([
            Logger::DEBUG => $this->ansi->color(SGR::COLOR_FG_WHITE)->get(),
            Logger::INFO => $this->ansi->color(SGR::COLOR_FG_WHITE_BRIGHT)->get(),
            Logger::NOTICE => $this->ansi->color(SGR::COLOR_FG_GREEN)->get(),
            Logger::WARNING => $this->ansi->color(SGR::COLOR_FG_YELLOW)->get(),
            Logger::ERROR => $this->ansi->color(SGR::COLOR_FG_RED)->get(),
            Logger::CRITICAL => $this->ansi->color(SGR::COLOR_FG_RED)->get(),
            Logger::ALERT => $this->ansi->color(SGR::COLOR_FG_RED)->get(),
            Logger::EMERGENCY => $this->ansi->color(SGR::COLOR_FG_RED)->get(),
        ]);
    }
}