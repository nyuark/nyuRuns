<?php

// Complete PHP error handling
// Inspired from DigitalJedi805's solution
// https://stackoverflow.com/questions/27238473/how-can-i-catch-all-errors-in-php/27238474#27238474
//$ErrorHandler = new ErrorHandler();

$GLOBALS['ErrorCallback'] = "hndlError";
$GLOBALS['ExceptionCallback'] = "hndlExcpt";
$GLOBALS['FatalCallback'] = "hndlFatal";

$GLOBALS['EnableReporting'] = true;
$GLOBALS['ErrorLevel'] = E_ALL;

/**
 * Registers the configured error, exception and fatal-error callbacks
 * with PHP and disables the engine's own error/warning display, or
 * fully silences error output when reporting is disabled.
 */
function initError(): Void
{
    if($GLOBALS["EnableReporting"])
    {
        error_reporting($GLOBALS["ErrorLevel"]);

        if( isset($GLOBALS["ErrorCallback"]) && strlen($GLOBALS["ErrorCallback"]) > 0 )
        {
            set_error_handler($GLOBALS["ErrorCallback"]);

            // Prevent the PHP engine from displaying runtime errors on its own
            ini_set('display_errors',false);
        }
        else
            ini_set('display_errors',true);

        if( isset($GLOBALS["FatalCallback"]) && strlen($GLOBALS["FatalCallback"]) > 0 )
        {
            register_shutdown_function($GLOBALS["FatalCallback"]);

            // Prevent the PHP engine from displaying fatal errors on its own
            ini_set('display_startup_errors',false);
        }
        else
            ini_set('display_startup_errors',true);

        if( isset($GLOBALS['ExceptionCallback']) && strlen($GLOBALS['ExceptionCallback']) > 0 )
            set_exception_handler($GLOBALS["ExceptionCallback"]);
    }
    else
    {
        ini_set('display_errors',0);
        ini_set('display_startup_errors',0);
        error_reporting(0);
    }
}

/**
 * PHP error handler callback: forwards the error to the global
 * ErrorHandler if one is set, otherwise prints it immediately.
 *
 * @param int    $errorLevel   PHP error level constant (e.g. E_WARNING).
 * @param String $errMessage The error message.
 * @param String $errFilePth    File where the error occurred.
 * @param int    $errLineNum    Line where the error occurred.
 * @param mixed  $errContext Variable scope at the point of the error.
 * @return Bool Always true, to tell PHP the error was handled.
 */
function hndlError( Int|String $errorLevel, String $errMessage, ?String $errFilePth=null, ?Int $errLineNum=null, Mixed $errContext=null ): Bool
{
    if( isset($GLOBALS['ErrorHandler']))
    {
        //  Pass errors up to the global ErrorHandler to be later inserted into
        // final output at the appropriate time.
        $GLOBALS['ErrorHandler']->pushError($errorLevel,$errMessage,$errFilePth,$errLineNum,$errContext);

        return true;
    }
    else
    {
        showError($errorLevel,$errMessage,$errFilePth,$errLineNum,$errContext);
        return true;
    }
}

/**
 * PHP exception handler callback: forwards the exception's details to
 * the configured error callback, or prints them directly if none is set.
 *
 * @param \Throwable $exceptnObj The uncaught exception.
 * @return Bool Always true, to tell PHP the exception was handled.
 */
function hndlExcpt( Throwable $exceptnObj ): Bool
{
    if( !headers_sent() ){
        http_response_code(500);
    }

    $excMssg = get_class($exceptnObj) . ' (code ' . $exceptnObj->getCode() . '): ' . $exceptnObj->getMessage();

    // Answer in the configured output format when a responder is registered
    if( sendReply('E_EXCEPTION', $excMssg, $exceptnObj->getFile(), $exceptnObj->getLine(), $exceptnObj->getTrace()) )
        return true;

    if( isset($GLOBALS['ErrorCallback']))
    {
        // Parse and pass exceptions up to the standard error callback, under their own level.
        $GLOBALS['ErrorCallback']('E_EXCEPTION', $excMssg, $exceptnObj->getFile(), $exceptnObj->getLine(), $exceptnObj->getTrace());

        return true;
    }
    else
    {
        showError('E_EXCEPTION', $excMssg, $exceptnObj->getFile(), $exceptnObj->getLine(), $exceptnObj->getTrace());
        return true;
    }
}

/**
 * Logs an uncaught exception and answers the request in the configured
 * output format, through the responder registered by the Runner. Does
 * nothing when no responder is available or when output has already
 * started.
 *
 * @param int|String $errorLevel PHP error level constant, or 'E_EXCEPTION'.
 * @param String     $errMessage The error message.
 * @param String     $errFilePth File where the error occurred.
 * @param int        $errLineNum Line where the error occurred.
 * @param mixed      $errContext Stack trace at the point of the error.
 * @return Bool True when the response was sent.
 */
function sendReply( Int|String $errorLevel, String $errMessage, ?String $errFilePth=null, ?Int $errLineNum=null, Mixed $errContext=null ): Bool
{
    $hndlObj = $GLOBALS['ErrorHandler'] ?? null;
    $rspndFn = $GLOBALS['ErrorResponse'] ?? null;
    if( $hndlObj===null || !is_callable($rspndFn) || headers_sent() )
        return false;

    try
    {
        $hndlObj->logsError($errorLevel,$errMessage,$errFilePth,$errLineNum,$errContext);
        if( $hndlObj->showsErrs() )
            $rspndFn( $errMessage.' in '.$errFilePth.' on line '.$errLineNum );
        else
            $rspndFn( 'Internal Server Error' );
        return true;
    }
    catch( Throwable $e )
    {
        // The responder itself failed: fall back to the plain display
        return false;
    }
}

/**
 * Shutdown-function callback: checks the last PHP error and, if it was
 * fatal, forwards it to the configured error callback (or prints it
 * directly). Does nothing on a clean shutdown.
 */
function hndlFatal(): Void
{
    $lastErr = error_get_last();

    // Unset Error Type and Message implies a proper shutdown.
    if( !isset($lastErr['type']) && !isset($lastErr['message']))
        exit();

    if( in_array($lastErr['type'], Array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR)) && !headers_sent() ){
        http_response_code(500);
    }

    if( isset($GLOBALS['ErrorCallback']))
    {
        // Pass fatal errors up to the standard error callback.
        $GLOBALS["ErrorCallback"]($lastErr['type'], $lastErr['message'],$lastErr['file'],$lastErr['line']);

        return;
    }
    else
    {
        showError($lastErr['type'], $lastErr['message'],$lastErr['file'],$lastErr['line']);
        return;
    }
}

// In the event that our 'ErrorHandler' class is in fact the generator of the error,
// we need a plain-Jane method that will still deliver the message.
/**
 * Renders an error as an HTML block and echoes it directly, bypassing
 * the ErrorHandler collection mechanism — used as a fallback when no
 * ErrorHandler is available.
 *
 * @param int    $errorLevel   PHP error level constant, or an exception code.
 * @param String $errMessage The error message.
 * @param String $errFilePth    File where the error occurred.
 * @param int    $errLineNum    Line where the error occurred.
 * @param mixed  $errContext Variable scope or stack trace at the point of the error.
 */
function showError( Int|String $errorLevel, String $errMessage, ?String $errFilePth=null, ?Int $errLineNum=null, Mixed $errContext=null ): Void
{
    if( class_exists("ErrorHandler"))
        $typeStr = ErrorHandler::errorType($errorLevel);
    else
        $typeStr = "$errorLevel";

    if( isset($errContext) && !is_array($errContext) && strlen($errContext) > 0 )
        $errContext = str_replace("#", "<br/>\r\n#", $errContext);

    $retnVal = "";
    $retnVal .= "<div class=\"$typeStr\" style=\"margin: 10px;\">\r\n";

    $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Error Level:</span> <span class=\"ErrorValue\">$typeStr</span></p>\r\n";

    if( isset($errFilePth) && strlen($errFilePth) > 0 )
        $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">File:</span> <span class=\"ErrorValue\">'$errFilePth'</span></p>\r\n";

    if( isset($errLineNum) && strlen($errLineNum) > 0 )
        $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Line:</span> <span class=\"ErrorValue\">$errLineNum</span></p>\r\n";

    if( isset($errContext) && is_array($errContext))
        $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Context:</span><span class=\"ErrorValue\">" . var_export($errContext,true) . "</span></p>\r\n";
    else if( isset($errContext) && strlen($errContext) > 0 )
        $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Context:</span><span class=\"ErrorValue\">$errContext</span></p>\r\n";

    $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Message:</span> <span class=\"ErrorValue\">" . str_replace("\r\n","<br/>\r\n",$errMessage) . "</span></p>\r\n";

    $retnVal .= "</div>\r\n";

    echo($retnVal);
}

class ErrorHandler
{
    protected $logrInst;

    /**
     * @param mixed $loggerInst A logger instance (nyu\Tools\Logger) to log through, or null to echo (fallback, e.g. CLI/dev).
     */
    public function __construct( ?\nyu\Tools\Logger $loggerInst = null )
    {
        $this->logrInst = $loggerInst;
    }

    /**
     * Logs the error via the configured logger, or echoes an HTML block
     * directly when no logger is available.
     *
     * @param int    $errorLevel   PHP error level constant, or an exception code.
     * @param String $errMessage The error message.
     * @param String $errFilePth    File where the error occurred.
     * @param int    $errLineNum    Line where the error occurred.
     * @param mixed  $errContext Variable scope or stack trace at the point of the error.
     */
    public function pushError( Int|String $errorLevel, String $errMessage, ?String $errFilePth=null, ?Int $errLineNum=null, Mixed $errContext=null ): Void
    {
        $this->logsError($errorLevel,$errMessage,$errFilePth,$errLineNum,$errContext);
        if( !$this->showsErrs() )
        {
            return;
        }
        // Errors may be shown: also echo below.

        $typeStr = ErrorHandler::errorType($errorLevel);

        if( isset($errContext) && !is_array($errContext) && strlen($errContext) > 0 )
            $errContext = str_replace("#", "<br/>\r\n#", $errContext);

        $retnVal = "";
        $retnVal .= "<div class=\"$typeStr\" style=\"margin: 10px;\">\r\n";

        $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Error Level:</span> <span class=\"ErrorValue\">$typeStr</span></p>\r\n";

        if( isset($errFilePth) && strlen($errFilePth) > 0 )
            $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">File:</span> <span class=\"ErrorValue\">'$errFilePth'</span></p>\r\n";

        if( isset($errLineNum) && strlen($errLineNum) > 0 )
            $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Line:</span> <span class=\"ErrorValue\">$errLineNum</span></p>\r\n";

        if( isset($errContext) && is_array($errContext))
            $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Context:</span><span class=\"ErrorValue\">" . var_export($errContext,true) . "</span></p>\r\n";
        else if( isset($errContext) && strlen($errContext) > 0 )
            $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Context:</span><span class=\"ErrorValue\">$errContext</span></p>\r\n";

        $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Message:</span> <span class=\"ErrorValue\">" . str_replace("\r\n","<br/>\r\n",$errMessage) . "</span></p>\r\n";

        $retnVal .= "</div>\r\n";

        echo($retnVal);
    }

    /**
     * Writes the error to the configured logger, at the log level matching
     * its error level. Does nothing when no logger is available.
     *
     * @param int    $errorLevel PHP error level constant, or 'E_EXCEPTION'.
     * @param String $errMessage The error message.
     * @param String $errFilePth File where the error occurred.
     * @param int    $errLineNum Line where the error occurred.
     * @param mixed  $errContext Variable scope or stack trace at the point of the error.
     */
    public function logsError( Int|String $errorLevel, String $errMessage, ?String $errFilePth=null, ?Int $errLineNum=null, Mixed $errContext=null ): Void
    {
        if( $this->logrInst === null )
        {
            return;
        }

        $logMssg = ErrorHandler::errorType($errorLevel).': '.$errMessage;
        if( isset($errFilePth) ) $logMssg .= ' in '.$errFilePth;
        if( isset($errLineNum) ) $logMssg .= ' on line '.$errLineNum;
        $logCntx = is_array($errContext) ? $errContext : Array();

        switch( $errorLevel )
        {
            case E_ERROR: case E_USER_ERROR: case E_CORE_ERROR: case E_COMPILE_ERROR: case E_RECOVERABLE_ERROR: case 'E_EXCEPTION':
                $this->logrInst->error($logMssg, $logCntx);
                break;
            case E_WARNING: case E_USER_WARNING: case E_CORE_WARNING: case E_COMPILE_WARNING:
                $this->logrInst->warning($logMssg, $logCntx);
                break;
            case E_NOTICE: case E_USER_NOTICE: case E_STRICT: case E_DEPRECATED: case E_USER_DEPRECATED:
                $this->logrInst->notice($logMssg, $logCntx);
                break;
            default:
                $this->logrInst->info($logMssg, $logCntx);
        }
    }

    /**
     * Tells whether error details may be shown to the client: always when
     * no logger is available, otherwise only when ERR_SHOW is on.
     *
     * @return Bool True when error details may be shown.
     */
    public function showsErrs(): Bool
    {
        if( $this->logrInst === null )
        {
            return true;
        }

        $errShow = false;
        if( isset($GLOBALS['nyuConfig']) && $GLOBALS['nyuConfig']!==null )
        {
            $errShow = $GLOBALS['nyuConfig']->getRowVal('SYSTEM','ERR_SHOW') ?? false;
        }
        return (bool)$errShow;
    }

    /**
     * Maps a PHP error-level constant to its human-readable name.
     *
     * @param int $errorLevel PHP error level constant (e.g. E_WARNING, E_NOTICE).
     * @return String The matching name (e.g. 'E_WARNING'), or 'E_UNSPECIFIED_ERROR' if unrecognized.
     */
    public static function errorType( Int|String $errorLevel ): String
    {
        $retnVal = "";

        switch( $errorLevel )
        {
            default:
                $retnVal = "E_UNSPECIFIED_ERROR";
                break;
            case E_ERROR: // 1 //
                $retnVal = 'E_ERROR';
                break;
            case E_WARNING: // 2 //
                $retnVal = 'E_WARNING';
                break;
            case E_PARSE: // 4 //
                $retnVal = 'E_PARSE';
                break;
            case E_NOTICE: // 8 //
                $retnVal = 'E_NOTICE';
                break;
            case E_CORE_ERROR: // 16 //
                $retnVal = 'E_CORE_ERROR';
                break;
            case E_CORE_WARNING: // 32 //
                $retnVal = 'E_CORE_WARNING';
                break;
            case E_COMPILE_ERROR: // 64 //
                $retnVal = 'E_COMPILE_ERROR';
                break;
            case E_COMPILE_WARNING: // 128 //
                $retnVal = 'E_COMPILE_WARNING';
                break;
            case E_USER_ERROR: // 256 //
                $retnVal = 'E_USER_ERROR';
                break;
            case E_USER_WARNING: // 512 //
                $retnVal = 'E_USER_WARNING';
                break;
            case E_USER_NOTICE: // 1024 //
                $retnVal = 'E_USER_NOTICE';
                break;
            case E_STRICT: // 2048 //
                $retnVal = 'E_STRICT';
                break;
            case E_RECOVERABLE_ERROR: // 4096 //
                $retnVal = 'E_RECOVERABLE_ERROR';
                break;
            case E_DEPRECATED: // 8192 //
                $retnVal = 'E_DEPRECATED';
                break;
            case E_USER_DEPRECATED: // 16384 //
                $retnVal = 'E_USER_DEPRECATED';
                break;
            case 'E_EXCEPTION': // uncaught exception, see hndlExcpt() //
                $retnVal = 'E_EXCEPTION';
                break;
        }

        return $retnVal;
    }
}

$GLOBALS['ErrorHandler'] = new ErrorHandler( $GLOBALS['nyuLogger'] ?? null );
