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
 * @param Int|String $errorLevel   PHP error level constant (e.g. E_WARNING).
 * @param String $errMessage The error message.
 * @param ?String $errFilePth    File where the error occurred.
 * @param ?int   $errLineNum    Line where the error occurred.
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
 * Logs an uncaught exception or fatal error and answers the request in the configured
 * output format, through the responder registered by the Runner. Does
 * nothing when no responder is available or when output has already
 * started.
 *
 * @param int|String $errorLevel PHP error level constant, or 'E_EXCEPTION'.
 * @param String     $errMessage The error message.
 * @param ?String    $errFilePth File where the error occurred.
 * @param ?int       $errLineNum Line where the error occurred.
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
        // The responder itself failed: drop it and fall back to the plain display
        unset($GLOBALS['ErrorResponse']);
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

    // Unset Error Type and Message implies a proper shutdown : the shutdown functions registered after this one still run
    if( !isset($lastErr['type']) && !isset($lastErr['message']))
        return;

    $isFatal = in_array($lastErr['type'], Array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR));
    if( $isFatal && !headers_sent() ){
        http_response_code(500);
    }

    // Answer in the configured output format when a responder is registered
    if( $isFatal && sendReply($lastErr['type'], $lastErr['message'], $lastErr['file'], $lastErr['line']) )
        return;

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

/**
 * Reduces a stack trace to what tells where the error happened: for
 * each call, its file, its line and its name. The arguments of the
 * calls, which may hold a password or a key, and the objects they were
 * made on are left out.
 *
 * @param Array $traceArray Frames of the trace, as Throwable::getTrace() gives them.
 * @return Array The frames, each with its 'file', 'line', 'class', 'type' and 'function' when it has them.
 */
function plainTrce( Array $traceArray ): Array
{
    $keptLst = Array();
    foreach( $traceArray as $oneFrm )
    {
        if( is_array($oneFrm) )
            $keptLst[] = array_intersect_key($oneFrm, Array('file'=>1, 'line'=>1, 'class'=>1, 'type'=>1, 'function'=>1));
    }
    return $keptLst;
}

/**
 * Renders a stack trace as an HTML table: one row per call, telling
 * the call, its file and its line. The values of the arguments are
 * never shown, and every text is HTML-encoded.
 *
 * @param Array $traceArray Frames of the trace, as Throwable::getTrace() gives them.
 * @return String The table, or an empty string when the trace holds no frame.
 */
function stackTble( Array $traceArray ): String
{
    $rowsTxt = "";
    $rowNmbr = 0;
    foreach( $traceArray as $oneFrm )
    {
        if( !is_array($oneFrm) )
            continue;

        $callTxt = strval($oneFrm['class'] ?? '') . strval($oneFrm['type'] ?? '') . strval($oneFrm['function'] ?? '');
        if( isset($oneFrm['function']) )
            $callTxt .= '()';

        $rowsTxt .= "<tr><td>#$rowNmbr</td>";
        $rowsTxt .= "<td>" . htmlspecialchars($callTxt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</td>";
        $rowsTxt .= "<td>" . htmlspecialchars(strval($oneFrm['file'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</td>";
        $rowsTxt .= "<td>" . (isset($oneFrm['line']) ? intval($oneFrm['line']) : '') . "</td></tr>\r\n";
        $rowNmbr++;
    }

    if( $rowsTxt === "" )
        return "";

    return "<table class=\"ErrorStack\" style=\"border-collapse: collapse;\">\r\n"
        . "<tr><th>#</th><th>Call</th><th>File</th><th>Line</th></tr>\r\n"
        . $rowsTxt . "</table>\r\n";
}

/**
 * Renders an error as an HTML block: its level, file, line, context and
 * message, every text HTML-encoded. A context that is a stack trace is
 * shown as a table (see stackTble); one that is a text is shown as it
 * is; any other is left out. This is the one display of an error when
 * errors may be shown and the reply is HTML: showError() and
 * ErrorHandler::pushError() both echo it.
 *
 * @param int|String $errorLevel PHP error level constant, or 'E_EXCEPTION'.
 * @param String     $errMessage The error message.
 * @param ?String    $errFilePth File where the error occurred.
 * @param ?int       $errLineNum Line where the error occurred.
 * @param mixed      $errContext Stack trace, or text, at the point of the error.
 * @return String The HTML block.
 */
function errorBlck( Int|String $errorLevel, String $errMessage, ?String $errFilePth=null, ?Int $errLineNum=null, Mixed $errContext=null ): String
{
    $encodFn = function( String $plainValue ): String { return htmlspecialchars($plainValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $typeStr = ErrorHandler::errorType($errorLevel);

    $retnVal = "";
    $retnVal .= "<div class=\"$typeStr\" style=\"margin: 10px;\">\r\n";

    $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Error Level:</span> <span class=\"ErrorValue\">$typeStr</span></p>\r\n";

    if( isset($errFilePth) && strlen($errFilePth) > 0 )
        $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">File:</span> <span class=\"ErrorValue\">'" . $encodFn($errFilePth) . "'</span></p>\r\n";

    if( isset($errLineNum) )
        $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Line:</span> <span class=\"ErrorValue\">$errLineNum</span></p>\r\n";

    // A trace is a list of frames : anything else an array may hold is not shown
    $stckTxt = (is_array($errContext) && is_array(reset($errContext))) ? stackTble($errContext) : "";
    if( $stckTxt !== "" )
        $retnVal .= "<div class=\"ErrorData\"><span class=\"ErrorKey\">Stack:</span>\r\n" . $stckTxt . "</div>\r\n";
    else if( is_string($errContext) && strlen($errContext) > 0 )
        $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Context:</span><span class=\"ErrorValue\">" . implode("<br/>\r\n#", array_map($encodFn, explode("#", $errContext))) . "</span></p>\r\n";

    $retnVal .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Message:</span> <span class=\"ErrorValue\">" . str_replace("\r\n","<br/>\r\n",$encodFn($errMessage)) . "</span></p>\r\n";

    $retnVal .= "</div>\r\n";

    return $retnVal;
}

// In the event that our 'ErrorHandler' class is in fact the generator of the error,
// we need a plain-Jane method that will still deliver the message.
/**
 * Renders an error as an HTML block (see errorBlck) and echoes it directly, bypassing
 * the ErrorHandler collection mechanism — used as a fallback when no
 * ErrorHandler is available.
 *
 * @param Int|String $errorLevel   PHP error level constant, or an exception code.
 * @param String $errMessage The error message.
 * @param ?String $errFilePth    File where the error occurred.
 * @param ?int   $errLineNum    Line where the error occurred.
 * @param mixed  $errContext Variable scope or stack trace at the point of the error.
 */
function showError( Int|String $errorLevel, String $errMessage, ?String $errFilePth=null, ?Int $errLineNum=null, Mixed $errContext=null ): Void
{
    echo(errorBlck($errorLevel,$errMessage,$errFilePth,$errLineNum,$errContext));
}

/**
 * Collects the errors of a request: each one is logged, kept in a stack,
 * and shown when errors may be shown.
 */
class ErrorHandler
{
    protected $logrInst;
    protected $errStack = Array();

    /**
     * Builds the error handler, with the logger it writes through.
     *
     * @param ?\nyu\Tools\Logger $loggerInst A logger instance (nyu\Tools\Logger) to log through, or null to echo (fallback, e.g. CLI/dev).
     */
    public function __construct( ?\nyu\Tools\Logger $loggerInst = null )
    {
        $this->logrInst = $loggerInst;
    }

    /**
     * Logs the error via the configured logger. When errors may be shown,
     * it is kept for the response body inside a framework request, and
     * echoed as an HTML block otherwise (see errorBlck).
     *
     * @param Int|String $errorLevel   PHP error level constant, or an exception code.
     * @param String $errMessage The error message.
     * @param ?String $errFilePth    File where the error occurred.
     * @param ?int   $errLineNum    Line where the error occurred.
     * @param mixed  $errContext Variable scope or stack trace at the point of the error.
     */
    public function pushError( Int|String $errorLevel, String $errMessage, ?String $errFilePth=null, ?Int $errLineNum=null, Mixed $errContext=null ): Void
    {
        $this->logsError($errorLevel,$errMessage,$errFilePth,$errLineNum,$errContext);
        if( !$this->showsErrs() )
        {
            return;
        }
        // Errors may be shown: kept for the response, or echoed below.

        $typeStr = ErrorHandler::errorType($errorLevel);

        if( isset($GLOBALS['ErrorResponse']) )
        {
            // Inside a framework request: kept for the response body instead of echoed
            $this->errStack[] = $typeStr.': '.$errMessage.' in '.$errFilePth.' on line '.$errLineNum;
            return;
        }

        echo(errorBlck($errorLevel,$errMessage,$errFilePth,$errLineNum,$errContext));
    }

    /**
     * Returns the errors collected for display during the request, and
     * forgets them.
     *
     * @return Array The collected errors, as 'LEVEL: message in file on line N' strings.
     */
    public function takeStack(): Array
    {
        $stckArr = $this->errStack;
        $this->errStack = Array();
        return $stckArr;
    }

    /**
     * Writes the error to the configured logger, at the log level matching
     * its error level. Does nothing when no logger is available.
     *
     * @param Int|String $errorLevel PHP error level constant, or 'E_EXCEPTION'.
     * @param String $errMessage The error message.
     * @param ?String $errFilePth File where the error occurred.
     * @param ?int   $errLineNum Line where the error occurred.
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
        // A trace is logged without the arguments of its calls
        $logCntx = is_array($errContext) ? plainTrce($errContext) : Array();

        switch( $errorLevel )
        {
            case E_ERROR: case E_USER_ERROR: case E_CORE_ERROR: case E_COMPILE_ERROR: case E_RECOVERABLE_ERROR: case 'E_EXCEPTION':
                $this->logrInst->error($logMssg, $logCntx);
                break;
            case E_WARNING: case E_USER_WARNING: case E_CORE_WARNING: case E_COMPILE_WARNING:
                $this->logrInst->warning($logMssg, $logCntx);
                break;
            case E_NOTICE: case E_USER_NOTICE: case E_DEPRECATED: case E_USER_DEPRECATED:
                $this->logrInst->notice($logMssg, $logCntx);
                break;
            default:
                $this->logrInst->info($logMssg, $logCntx);
        }
    }

    /**
     * Tells whether error details may be shown to the client: always when
     * no logger is available, otherwise only when ERR_SHOW is on, and
     * never in the PROD environment (ENV_NAME).
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
            // In production nothing of an error is shown, whatever ERR_SHOW says
            if( strtoupper(trim(strval($GLOBALS['nyuConfig']->getRowVal('SYSTEM','ENV_NAME')))) == 'PROD' )
                return false;

            $errShow = $GLOBALS['nyuConfig']->getRowVal('SYSTEM','ERR_SHOW') ?? false;
        }
        return (bool)$errShow;
    }

    /**
     * Maps a PHP error-level constant to its human-readable name.
     *
     * @param Int|String $errorLevel PHP error level constant (e.g. E_WARNING, E_NOTICE).
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
