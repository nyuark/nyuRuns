<?php

// Complete PHP error handling
// Inspired from DigitalJedi805's solution
// https://stackoverflow.com/questions/27238473/how-can-i-catch-all-errors-in-php/27238474#27238474
//$ErrorHandler = new ErrorHandler();

$GLOBALS['ErrorCallback'] = "treatErrorHook";
$GLOBALS['ExceptionCallback'] = "treatException";
$GLOBALS['FatalCallback'] = "treatLastError";

$GLOBALS['EnableReporting'] = true;
$GLOBALS['ErrorLevel'] = E_ALL;

/**
 * Registers the configured error, exception and fatal-error callbacks
 * with PHP and disables the engine's own error/warning display, or
 * fully silences error output when reporting is disabled.
 */
function installHandler(): Void
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
 * @param Int|String $errLevelNumb   PHP error level constant (e.g. E_WARNING).
 * @param String $errorMessage The error message.
 * @param ?String $errorFileTxt    File where the error occurred.
 * @param ?int   $errorLineNum    Line where the error occurred.
 * @param mixed  $errorContext Variable scope at the point of the error.
 * @return Bool Always true, to tell PHP the error was handled.
 */
function treatErrorHook( Int|String $errLevelNumb, String $errorMessage, ?String $errorFileTxt=null, ?Int $errorLineNum=null, Mixed $errorContext=null ): Bool
{
    // What the error level leaves out (ERR_LEVL), or the @ operator silences, is neither shown nor logged; an exception (E_EXCEPTION) always is
    if( is_int($errLevelNumb) && !(error_reporting() & $errLevelNumb) )
    {
        return true;
    }
    if( isset($GLOBALS['ErrorHandler']))
    {
        //  Pass errors up to the global ErrorHandler to be later inserted into
        // final output at the appropriate time.
        $GLOBALS['ErrorHandler']->treatEachError($errLevelNumb,$errorMessage,$errorFileTxt,$errorLineNum,$errorContext);

        return true;
    }
    else
    {
        writeErrorHtml($errLevelNumb,$errorMessage,$errorFileTxt,$errorLineNum,$errorContext);
        return true;
    }
}

/**
 * PHP exception handler callback: forwards the exception's details to
 * the configured error callback, or prints them directly if none is set.
 *
 * @param \Throwable $exceptionObj The uncaught exception.
 * @return Bool Always true, to tell PHP the exception was handled.
 */
function treatException( Throwable $exceptionObj ): Bool
{
    if( !headers_sent() ){
        http_response_code(500);
    }

    $errorTxt = get_class($exceptionObj) . ' (code ' . $exceptionObj->getCode() . '): ' . $exceptionObj->getMessage();

    // Answer in the configured output format when a responder is registered
    if( sendErrorReply('E_EXCEPTION', $errorTxt, $exceptionObj->getFile(), $exceptionObj->getLine(), $exceptionObj->getTrace()) )
        return true;

    if( isset($GLOBALS['ErrorCallback']))
    {
        // Parse and pass exceptions up to the standard error callback, under their own level.
        $GLOBALS['ErrorCallback']('E_EXCEPTION', $errorTxt, $exceptionObj->getFile(), $exceptionObj->getLine(), $exceptionObj->getTrace());

        return true;
    }
    else
    {
        writeErrorHtml('E_EXCEPTION', $errorTxt, $exceptionObj->getFile(), $exceptionObj->getLine(), $exceptionObj->getTrace());
        return true;
    }
}

/**
 * Logs an uncaught exception or fatal error and answers the request in the configured
 * output format, through the responder registered by the Runner. Does
 * nothing when no responder is available or when output has already
 * started.
 *
 * @param int|String $errLevelNumb PHP error level constant, or 'E_EXCEPTION'.
 * @param String     $errorMessage The error message.
 * @param ?String    $errorFileTxt File where the error occurred.
 * @param ?int       $errorLineNum Line where the error occurred.
 * @param mixed      $errorContext Stack trace at the point of the error.
 * @return Bool True when the response was sent.
 */
function sendErrorReply( Int|String $errLevelNumb, String $errorMessage, ?String $errorFileTxt=null, ?Int $errorLineNum=null, Mixed $errorContext=null ): Bool
{
    $hndlInst = $GLOBALS['ErrorHandler'] ?? null;
    $respInst = $GLOBALS['ErrorResponse'] ?? null;
    if( $hndlInst===null || !is_callable($respInst) || headers_sent() )
        return false;

    try
    {
        $hndlInst->writeErrorLogs($errLevelNumb,$errorMessage,$errorFileTxt,$errorLineNum,$errorContext);
        if( $hndlInst->canShowDetails() )
            $respInst( $errorMessage.' in '.$errorFileTxt.' on line '.$errorLineNum );
        else
            $respInst( 'Internal Server Error' );
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
function treatLastError(): Void
{
    $lastErro = error_get_last();

    // Unset Error Type and Message implies a proper shutdown : the shutdown functions registered after this one still run
    if( !isset($lastErro['type']) && !isset($lastErro['message']))
        return;

    $wasFatal = in_array($lastErro['type'], Array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR));
    // What was not fatal was handled when it was raised, or silenced on purpose (@): nothing is left to do at shutdown
    if( !$wasFatal ){
        return;
    }
    if( $wasFatal && !headers_sent() ){
        http_response_code(500);
    }

    // Answer in the configured output format when a responder is registered
    if( $wasFatal && sendErrorReply($lastErro['type'], $lastErro['message'], $lastErro['file'], $lastErro['line']) )
        return;

    if( isset($GLOBALS['ErrorCallback']))
    {
        // Pass fatal errors up to the standard error callback.
        $GLOBALS["ErrorCallback"]($lastErro['type'], $lastErro['message'],$lastErro['file'],$lastErro['line']);

        return;
    }
    else
    {
        writeErrorHtml($lastErro['type'], $lastErro['message'],$lastErro['file'],$lastErro['line']);
        return;
    }
}

/**
 * Reduces a stack trace to what tells where the error happened: for
 * each call, its file, its line and its name. The arguments of the
 * calls, which may hold a password or a key, and the objects they were
 * made on are left out.
 *
 * @param Array $callFrameSet Frames of the trace, as Throwable::getTrace() gives them.
 * @return Array The frames, each with its 'file', 'line', 'class', 'type' and 'function' when it has them.
 */
function castPlainTrace( Array $callFrameSet ): Array
{
    $keptList = Array();
    foreach( $callFrameSet as $oneFrm )
    {
        if( is_array($oneFrm) )
            $keptList[] = array_intersect_key($oneFrm, Array('file'=>1, 'line'=>1, 'class'=>1, 'type'=>1, 'function'=>1));
    }
    return $keptList;
}

/**
 * Renders a stack trace as an HTML table: one row per call, telling
 * the call, its file and its line. The values of the arguments are
 * never shown, and every text is HTML-encoded.
 *
 * @param Array $callFrameSet Frames of the trace, as Throwable::getTrace() gives them.
 * @return String The table, or an empty string when the trace holds no frame.
 */
function renderStackTbl( Array $callFrameSet ): String
{
    $rowsText = "";
    $rowCount = 0;
    foreach( $callFrameSet as $oneFrm )
    {
        if( !is_array($oneFrm) )
            continue;

        $callText = strval($oneFrm['class'] ?? '') . strval($oneFrm['type'] ?? '') . strval($oneFrm['function'] ?? '');
        if( isset($oneFrm['function']) )
            $callText .= '()';

        $rowsText .= "<tr><td>#$rowCount</td>";
        $rowsText .= "<td>" . htmlspecialchars($callText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</td>";
        $rowsText .= "<td>" . htmlspecialchars(strval($oneFrm['file'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</td>";
        $rowsText .= "<td>" . (isset($oneFrm['line']) ? intval($oneFrm['line']) : '') . "</td></tr>\r\n";
        $rowCount++;
    }

    if( $rowsText === "" )
        return "";

    return "<table class=\"ErrorStack\" style=\"border-collapse: collapse;\">\r\n"
        . "<tr><th>#</th><th>Call</th><th>File</th><th>Line</th></tr>\r\n"
        . $rowsText . "</table>\r\n";
}

/**
 * Renders an error as an HTML block: its level, file, line, context and
 * message, every text HTML-encoded. A context that is a stack trace is
 * shown as a table (see stackTble); one that is a text is shown as it
 * is; any other is left out. This is the one display of an error when
 * errors may be shown and the reply is HTML: writeErrorHtml() and
 * ErrorHandler::treatEachError() both echo it.
 *
 * @param int|String $errLevelNumb PHP error level constant, or 'E_EXCEPTION'.
 * @param String     $errorMessage The error message.
 * @param ?String    $errorFileTxt File where the error occurred.
 * @param ?int       $errorLineNum Line where the error occurred.
 * @param mixed      $errorContext Stack trace, or text, at the point of the error.
 * @return String The HTML block.
 */
function renderErrBlock( Int|String $errLevelNumb, String $errorMessage, ?String $errorFileTxt=null, ?Int $errorLineNum=null, Mixed $errorContext=null ): String
{
    $encdFunc = function( String $plainValText ): String { return htmlspecialchars($plainValText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $errorCod = ErrorHandler::getErrLevlName($errLevelNumb);

    $rtrnVals = "";
    $rtrnVals .= "<div class=\"$errorCod\" style=\"margin: 10px;\">\r\n";

    $rtrnVals .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Error Level:</span> <span class=\"ErrorValue\">$errorCod</span></p>\r\n";

    if( isset($errorFileTxt) && strlen($errorFileTxt) > 0 )
        $rtrnVals .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">File:</span> <span class=\"ErrorValue\">'" . $encdFunc($errorFileTxt) . "'</span></p>\r\n";

    if( isset($errorLineNum) )
        $rtrnVals .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Line:</span> <span class=\"ErrorValue\">$errorLineNum</span></p>\r\n";

    // A trace is a list of frames : anything else an array may hold is not shown
    $stackTxt = (is_array($errorContext) && is_array(reset($errorContext))) ? renderStackTbl($errorContext) : "";
    if( $stackTxt !== "" )
        $rtrnVals .= "<div class=\"ErrorData\"><span class=\"ErrorKey\">Stack:</span>\r\n" . $stackTxt . "</div>\r\n";
    else if( is_string($errorContext) && strlen($errorContext) > 0 )
        $rtrnVals .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Context:</span><span class=\"ErrorValue\">" . implode("<br/>\r\n#", array_map($encdFunc, explode("#", $errorContext))) . "</span></p>\r\n";

    $rtrnVals .= "<p class=\"ErrorData\"><span class=\"ErrorKey\">Message:</span> <span class=\"ErrorValue\">" . str_replace("\r\n","<br/>\r\n",$encdFunc($errorMessage)) . "</span></p>\r\n";

    $rtrnVals .= "</div>\r\n";

    return $rtrnVals;
}

// In the event that our 'ErrorHandler' class is in fact the generator of the error,
// we need a plain-Jane method that will still deliver the message.
/**
 * Renders an error as an HTML block (see errorBlck) and echoes it directly, bypassing
 * the ErrorHandler collection mechanism — used as a fallback when no
 * ErrorHandler is available.
 *
 * @param Int|String $errLevelNumb   PHP error level constant, or an exception code.
 * @param String $errorMessage The error message.
 * @param ?String $errorFileTxt    File where the error occurred.
 * @param ?int   $errorLineNum    Line where the error occurred.
 * @param mixed  $errorContext Variable scope or stack trace at the point of the error.
 */
function writeErrorHtml( Int|String $errLevelNumb, String $errorMessage, ?String $errorFileTxt=null, ?Int $errorLineNum=null, Mixed $errorContext=null ): Void
{
    echo(renderErrBlock($errLevelNumb,$errorMessage,$errorFileTxt,$errorLineNum,$errorContext));
}

/**
 * Collects the errors of a request: each one is logged, kept in a stack,
 * and shown when errors may be shown.
 */
class ErrorHandler
{
    protected $loggingObj;
    protected $errorStack = Array();

    /**
     * Builds the error handler, with the logger it writes through.
     *
     * @param ?\nyu\Tools\Logger $loggerObject A logger instance (nyu\Tools\Logger) to log through, or null to echo (fallback, e.g. CLI/dev).
     */
    public function __construct( ?\nyu\Tools\Logger $loggerObject = null )
    {
        $this->loggingObj = $loggerObject;
    }

    /**
     * Logs the error via the configured logger. When errors may be shown,
     * it is kept for the response body inside a framework request, and
     * echoed as an HTML block otherwise (see errorBlck).
     *
     * @param Int|String $errLevelNumb   PHP error level constant, or an exception code.
     * @param String $errorMessage The error message.
     * @param ?String $errorFileTxt    File where the error occurred.
     * @param ?int   $errorLineNum    Line where the error occurred.
     * @param mixed  $errorContext Variable scope or stack trace at the point of the error.
     */
    public function treatEachError( Int|String $errLevelNumb, String $errorMessage, ?String $errorFileTxt=null, ?Int $errorLineNum=null, Mixed $errorContext=null ): Void
    {
        $this->writeErrorLogs($errLevelNumb,$errorMessage,$errorFileTxt,$errorLineNum,$errorContext);
        if( !$this->canShowDetails() )
        {
            return;
        }
        // Errors may be shown: kept for the response, or echoed below.

        $errorCod = ErrorHandler::getErrLevlName($errLevelNumb);

        if( isset($GLOBALS['ErrorResponse']) )
        {
            // Inside a framework request: kept for the response body instead of echoed
            $this->errorStack[] = $errorCod.': '.$errorMessage.' in '.$errorFileTxt.' on line '.$errorLineNum;
            return;
        }

        echo(renderErrBlock($errLevelNumb,$errorMessage,$errorFileTxt,$errorLineNum,$errorContext));
    }

    /**
     * Returns the errors collected for display during the request, and
     * forgets them.
     *
     * @return Array The collected errors, as 'LEVEL: message in file on line N' strings.
     */
    public function getStackErrors(): Array
    {
        $stackLst = $this->errorStack;
        $this->errorStack = Array();
        return $stackLst;
    }

    /**
     * Writes the error to the configured logger, at the log level matching
     * its error level. Does nothing when no logger is available.
     *
     * @param Int|String $errLevelNumb PHP error level constant, or 'E_EXCEPTION'.
     * @param String $errorMessage The error message.
     * @param ?String $errorFileTxt File where the error occurred.
     * @param ?int   $errorLineNum Line where the error occurred.
     * @param mixed  $errorContext Variable scope or stack trace at the point of the error.
     */
    public function writeErrorLogs( Int|String $errLevelNumb, String $errorMessage, ?String $errorFileTxt=null, ?Int $errorLineNum=null, Mixed $errorContext=null ): Void
    {
        if( $this->loggingObj === null )
        {
            return;
        }

        $logStrng = ErrorHandler::getErrLevlName($errLevelNumb).': '.$errorMessage;
        if( isset($errorFileTxt) ) $logStrng .= ' in '.$errorFileTxt;
        if( isset($errorLineNum) ) $logStrng .= ' on line '.$errorLineNum;
        // A trace is logged without the arguments of its calls
        $ctxtList = is_array($errorContext) ? castPlainTrace($errorContext) : Array();

        switch( $errLevelNumb )
        {
            case E_ERROR: case E_USER_ERROR: case E_CORE_ERROR: case E_COMPILE_ERROR: case E_RECOVERABLE_ERROR: case 'E_EXCEPTION':
                $this->loggingObj->error($logStrng, $ctxtList);
                break;
            case E_WARNING: case E_USER_WARNING: case E_CORE_WARNING: case E_COMPILE_WARNING:
                $this->loggingObj->warning($logStrng, $ctxtList);
                break;
            case E_NOTICE: case E_USER_NOTICE: case E_DEPRECATED: case E_USER_DEPRECATED:
                $this->loggingObj->notice($logStrng, $ctxtList);
                break;
            default:
                $this->loggingObj->info($logStrng, $ctxtList);
        }
    }

    /**
     * Tells whether error details may be shown to the client: always when
     * no logger is available, otherwise only when ERR_SHOW is on, and
     * never in the PROD environment (ENV_NAME).
     *
     * @return Bool True when error details may be shown.
     */
    public function canShowDetails(): Bool
    {
        if( $this->loggingObj === null )
        {
            return true;
        }

        $errorShw = false;
        if( isset($GLOBALS['nyuConfig']) && $GLOBALS['nyuConfig']!==null )
        {
            // In production nothing of an error is shown, whatever ERR_SHOW says
            if( strtoupper(trim(strval($GLOBALS['nyuConfig']->getNestedValue('SYSTEM','ENV_NAME')))) == 'PROD' )
                return false;

            $errorShw = $GLOBALS['nyuConfig']->getNestedValue('SYSTEM','ERR_SHOW') ?? false;
        }
        return (bool)$errorShw;
    }

    /**
     * Maps a PHP error-level constant to its human-readable name.
     *
     * @param Int|String $errLevelNumb PHP error level constant (e.g. E_WARNING, E_NOTICE).
     * @return String The matching name (e.g. 'E_WARNING'), or 'E_UNSPECIFIED_ERROR' if unrecognized.
     */
    public static function getErrLevlName( Int|String $errLevelNumb ): String
    {
        $rtrnVals = "";

        switch( $errLevelNumb )
        {
            default:
                $rtrnVals = "E_UNSPECIFIED_ERROR";
                break;
            case E_ERROR: // 1 //
                $rtrnVals = 'E_ERROR';
                break;
            case E_WARNING: // 2 //
                $rtrnVals = 'E_WARNING';
                break;
            case E_PARSE: // 4 //
                $rtrnVals = 'E_PARSE';
                break;
            case E_NOTICE: // 8 //
                $rtrnVals = 'E_NOTICE';
                break;
            case E_CORE_ERROR: // 16 //
                $rtrnVals = 'E_CORE_ERROR';
                break;
            case E_CORE_WARNING: // 32 //
                $rtrnVals = 'E_CORE_WARNING';
                break;
            case E_COMPILE_ERROR: // 64 //
                $rtrnVals = 'E_COMPILE_ERROR';
                break;
            case E_COMPILE_WARNING: // 128 //
                $rtrnVals = 'E_COMPILE_WARNING';
                break;
            case E_USER_ERROR: // 256 //
                $rtrnVals = 'E_USER_ERROR';
                break;
            case E_USER_WARNING: // 512 //
                $rtrnVals = 'E_USER_WARNING';
                break;
            case E_USER_NOTICE: // 1024 //
                $rtrnVals = 'E_USER_NOTICE';
                break;
            case E_RECOVERABLE_ERROR: // 4096 //
                $rtrnVals = 'E_RECOVERABLE_ERROR';
                break;
            case E_DEPRECATED: // 8192 //
                $rtrnVals = 'E_DEPRECATED';
                break;
            case E_USER_DEPRECATED: // 16384 //
                $rtrnVals = 'E_USER_DEPRECATED';
                break;
            case 'E_EXCEPTION': // uncaught exception, see treatException() //
                $rtrnVals = 'E_EXCEPTION';
                break;
        }

        return $rtrnVals;
    }
}

$GLOBALS['ErrorHandler'] = new ErrorHandler( $GLOBALS['nyuLogger'] ?? null );
