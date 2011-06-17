<?php
/**
 * Description of ExpressionManager
 * (1) Does safe evaluation of PHP expressions.  Only registered Functions, Variables, and ReservedWords are allowed.
 *   (a) Functions include any math, string processing, conditional, formatting, etc. functions
 *   (b) Variables are typically the question name (question.title)
 *   (c) ReservedWords are any LimeReplacementField or Token, including all INSERTANS:SGQA codes
 * (2) This class can replace LimeSurvey's current process of resolving strings that contain LimeReplacementFields
 *   (a) String is split by expressions (by curly braces, but safely supporting strings and escaped curly braces)
 *   (b) Expressions (things surrounded by curly braces) are evaluated - thereby doing LimeReplacementField substitution and/or more complex calculations
 *   (c) Non-expressions are left intact
 *   (d) The array of stringParts are re-joined to create the desired final string.
 *
 * At present, all variables are read-only, but this could be extended to support creation  of temporary variables and/or read-write access to registered variables
 *
 * @author Thomas M. White
 */

class ExpressionManager {
    // These three variables are effectively static once constructed
    private $sExpressionRegex;
    private $asTokenType;
    private $sTokenizerRegex;
    private $asCategorizeTokensRegex;
    private $amValidFunctions; // names and # params of valid functions
    private $amVars;    // names and values of valid variables
    private $amReservedWords;   // names and values of valid reserved words

    // Thes variables are used while  processing the equation
    private $expr;  // the source expression
    private $tokens;    // the list of generated tokens
    private $count; // total number of $tokens
    private $pos;   // position within the $token array while processing equation
    private $errs;    // array of syntax errors
    private $onlyparse;
    private $stack; // stack of intermediate results
    private $result;    // final result of evaluating the expression;
    private $evalStatus;    // true if $result is a valid result, and  there are no serious errors
    private $varsUsed;  // list of variables referenced in the equation
    private $reservedWordsUsed;  // list of reserved words used in the equation

    // These  variables are only used by sProcessStringContainingExpressions
    private $allVarsUsed;   // full list of variables used within the string, even if contains multiple expressions
    private $allReservedWordsUsed;  // full list of reserved words used in the string, even if  contains multiple expresions

    function __construct()
    {
        // List of token-matching regular expressions
        $regex_dq_string = '(?<!\\\\)".*?(?<!\\\\)"';
        $regex_sq_string = '(?<!\\\\)\'.*?(?<!\\\\)\'';
        $regex_whitespace = '\s+';
        $regex_lparen = '\(';
        $regex_rparen = '\)';
        $regex_comma = ',';
        $regex_not = '!';
        $regex_inc_dec = '\+\+|--';
        $regex_binary = '[+*/-]';
        $regex_compare = '<=|<|>=|>|==|!=|\ble\b|\blt\b|\bge\b|\bgt\b|\beq\b|\bne\b';
        $regex_assign = '=|\+=|-=|\*=|/=';
        $regex_sgqa = '[0-9]+X[0-9]+X[0-9]+[A-Z0-9_]*\#?[12]?';
        $regex_word = '[A-Z][A-Z0-9_]*:?[A-Z0-9_]*\.?[A-Z0-9_]*\.?[A-Z0-9_]*\.?[A-Z0-9_]*';
        $regex_number = '[0-9]+\.?[0-9]*|\.[0-9]+';
        $regex_andor = '\band\b|\bor\b|&&|\|\|';

        $this->sExpressionRegex = '#((?<!\\\\){(' . $regex_dq_string . '|' . $regex_sq_string . '|.*?)*(?<!\\\\)})#';

        // asTokenRegex and t_tokey_type must be kept in sync  (same number and order)
        $asTokenRegex = array(
            $regex_dq_string,
            $regex_sq_string,
            $regex_whitespace,
            $regex_lparen,
            $regex_rparen,
            $regex_comma,
            $regex_andor,
            $regex_compare,
            $regex_sgqa,
            $regex_word,
            $regex_number,
            $regex_not,
            $regex_inc_dec,
            $regex_assign,
            $regex_binary,
            );

        $this->asTokenType = array(
            'STRING',
            'STRING',
            'SPACE',
            'LP',
            'RP',
            'COMMA',
            'AND_OR',
            'COMPARE',
            'SGQA',
            'WORD',
            'NUMBER',
            'NOT',
            'OTHER',
            'ASSIGN',
            'BINARYOP',
           );

        // $sTokenizerRegex - a single regex used to split and equation into tokens
        $this->sTokenizerRegex = '#(' . implode('|',$asTokenRegex) . ')#i';

        // $asCategorizeTokensRegex - an array of patterns so can categorize the type of token found - would be nice if could get this from preg_split
        // Adding ability to capture 'OTHER' type, which indicates an error - unsupported syntax element
        $this->asCategorizeTokensRegex = preg_replace("#^(.*)$#","#^$1$#i",$asTokenRegex);
        $this->asCategorizeTokensRegex[] = '/.+/';
        $this->asTokenType[] = 'OTHER';
        
        // Each allowed function is a mapping from local name to external name + number of arguments
        // Functions can have -1 (meaning unlimited), or a list of serveral allowable #s of arguments.
        $this->amValidFunctions = array(
            'abs'			=>array('abs','Absolute value',1),
            'acos'			=>array('acos','Arc cosine',1),
            'acosh'			=>array('acosh','Inverse hyperbolic cosine',1),
            'asin'			=>array('asin','Arc sine',1),
            'asinh'			=>array('asinh','Inverse hyperbolic sine',1),
            'atan2'			=>array('atan2','Arc tangent of two variables',2),
            'atan'			=>array('atan','Arc tangent',1),
            'atanh'			=>array('atanh','Inverse hyperbolic tangent',1),
            'base_convert'	=>array('base_convert','Convert a number between arbitrary bases',3),
            'bindec'		=>array('bindec','Binary to decimal',1),
            'ceil'			=>array('ceil','Round fractions up',1),
            'cos'			=>array('cos','Cosine',1),
            'cosh'			=>array('cosh','Hyperbolic cosine',1),
            'decbin'		=>array('decbin','Decimal to binary',1),
            'dechex'		=>array('dechex','Decimal to hexadecimal',1),
            'decoct'		=>array('decoct','Decimal to octal',1),
            'deg2rad'		=>array('deg2rad','Converts the number in degrees to the radian equivalent',1),
            'exp'			=>array('exp','Calculates the exponent of e',1),
            'expm1'			=>array('expm1','Returns exp(number) - 1, computed in a way that is accurate even when the value of number is close to zero',1),
            'floor'			=>array('floor','Round fractions down',1),
            'fmod'			=>array('fmod','Returns the floating point remainder (modulo) of the division of the arguments',2),
            'getrandmax'	=>array('getrandmax','Show largest possible random value',0),
            'hexdec'		=>array('hexdec','Hexadecimal to decimal',1),
            'hypot'			=>array('hypot','Calculate the length of the hypotenuse of a right-angle triangle',2),
            'is_finite'		=>array('is_finite','Finds whether a value is a legal finite number',1),
            'is_infinite'	=>array('is_infinite','Finds whether a value is infinite',1),
            'is_nan'		=>array('is_nan','Finds whether a value is not a number',1),
            'lcg_value'		=>array('lcg_value','Combined linear congruential generator',0),
            'log10'			=>array('log10','Base-10 logarithm',1),
            'log1p'			=>array('log1p','Returns log(1 + number), computed in a way that is accurate even when the value of number is close to zero',1),
            'log'			=>array('log','Natural logarithm',1,2),
            'max'			=>array('max','Find highest value',-1),
            'min'			=>array('min','Find lowest value',-1),
            'mt_getrandmax'	=>array('mt_getrandmax','Show largest possible random value',0),
            'mt_rand'		=>array('mt_rand','Generate a better random value',0,2),
            'mt_srand'		=>array('mt_srand','Seed the better random number generator',0,1),
            'octdec'		=>array('octdec','Octal to decimal',1),
            'pi'			=>array('pi','Get value of pi',0),
            'pow'			=>array('pow','Exponential expression',2),
            'rad2deg'		=>array('rad2deg','Converts the radian number to the equivalent number in degrees',1),
            'rand'			=>array('rand','Generate a random integer',0,2),
            'round'			=>array('round','Rounds a float',1,2,3),
            'sin'			=>array('sin','Sine',1),
            'sinh'			=>array('sinh','Hyperbolic sine',1),
            'sqrt'			=>array('sqrt','Square root',1),
            'srand'			=>array('srand','Seed the random number generator',0,1),
            'sum'           =>array('array_sum','Calculate the sum of values in an array',-1),
            'tan'			=>array('tan','Tangent',1),
            'tanh'			=>array('tanh','Hyperbolic tangent',1),

            'empty'			=>array('empty','Determine whether a variable is empty',1),
            'intval'		=>array('intval','Get the integer value of a variable',1,2),
            'is_bool'		=>array('is_bool','Finds out whether a variable is a boolean',1),
            'is_float'		=>array('is_float','Finds whether the type of a variable is float',1),
            'is_int'		=>array('is_int','Find whether the type of a variable is integer',1),
            'is_null'		=>array('is_null','Finds whether a variable is NULL',1),
            'is_numeric'	=>array('is_numeric','Finds whether a variable is a number or a numeric string',1),
            'is_scalar'		=>array('is_scalar','Finds whether a variable is a scalar',1),
            'is_string'		=>array('is_string','Find whether the type of a variable is string',1),

            'addcslashes'	=>array('addcslashes','Quote string with slashes in a C style',2),
            'addslashes'	=>array('addslashes','Quote string with slashes',1),
            'bin2hex'		=>array('bin2hex','Convert binary data into hexadecimal representation',1),
            'chr'			=>array('chr','Return a specific character',1),
            'chunk_split'	=>array('chunk_split','Split a string into smaller chunks',1,2,3),
            'convert_uudecode'			=>array('convert_uudecode','Decode a uuencoded string',1),
            'convert_uuencode'			=>array('convert_uuencode','Uuencode a string',1),
            'count_chars'	=>array('count_chars','Return information about characters used in a string',1,2),
            'crc32'			=>array('crc32','Calculates the crc32 polynomial of a string',1),
            'crypt'			=>array('crypt','One-way string hashing',1,2),
            'hebrev'		=>array('hebrev','Convert logical Hebrew text to visual text',1,2),
            'hebrevc'		=>array('hebrevc','Convert logical Hebrew text to visual text with newline conversion',1,2),
            'html_entity_decode'        =>array('html_entity_decode','Convert all HTML entities to their applicable characters',1,2,3),
            'htmlentities'	=>array('htmlentities','Convert all applicable characters to HTML entities',1,2,3),
            'htmlspecialchars_decode'	=>array('htmlspecialchars_decode','Convert special HTML entities back to characters',1,2),
            'htmlspecialchars'			=>array('htmlspecialchars','Convert special characters to HTML entities',1,2,3,4),
            'implode'		=>array('implode','Join array elements with a string',-1),
            'lcfirst'		=>array('lcfirst','Make a string\'s first character lowercase',1),
            'levenshtein'	=>array('levenshtein','Calculate Levenshtein distance between two strings',2,5),
            'ltrim'			=>array('ltrim','Strip whitespace (or other characters) from the beginning of a string',1,2),
            'md5'			=>array('md5','Calculate the md5 hash of a string',1),
            'metaphone'		=>array('metaphone','Calculate the metaphone key of a string',1,2),
            'money_format'	=>array('money_format','Formats a number as a currency string',1,2),
            'nl2br'			=>array('nl2br','Inserts HTML line breaks before all newlines in a string',1,2),
            'number_format'	=>array('number_format','Format a number with grouped thousands',1,2,4),
            'ord'			=>array('ord','Return ASCII value of character',1),
            'quoted_printable_decode'			=>array('quoted_printable_decode','Convert a quoted-printable string to an 8 bit string',1),
            'quoted_printable_encode'			=>array('quoted_printable_encode','Convert a 8 bit string to a quoted-printable string',1),
            'quotemeta'		=>array('quotemeta','Quote meta characters',1),
            'rtrim'			=>array('rtrim','Strip whitespace (or other characters) from the end of a string',1,2),
            'sha1'			=>array('sha1','Calculate the sha1 hash of a string',1),
            'similar_text'	=>array('similar_text','Calculate the similarity between two strings',1,2),
            'soundex'		=>array('soundex','Calculate the soundex key of a string',1),
            'sprintf'		=>array('sprintf','Return a formatted string',-1),
            'str_ireplace'  =>array('str_ireplace','Case-insensitive version of str_replace',3),
            'str_pad'		=>array('str_pad','Pad a string to a certain length with another string',2,3,4),
            'str_repeat'	=>array('str_repeat','Repeat a string',2),
            'str_replace'	=>array('str_replace','Replace all occurrences of the search string with the replacement string',3),
            'str_rot13'		=>array('str_rot13','Perform the rot13 transform on a string',1),
            'str_shuffle'	=>array('str_shuffle','Randomly shuffles a string',1),
            'str_word_count'	=>array('str_word_count','Return information about words used in a string',1),
            'strcasecmp'	=>array('strcasecmp','Binary safe case-insensitive string comparison',2),
            'strcmp'		=>array('strcmp','Binary safe string comparison',2),
            'strcoll'		=>array('strcoll','Locale based string comparison',2),
            'strcspn'		=>array('strcspn','Find length of initial segment not matching mask',2,3,4),
            'strip_tags'	=>array('strip_tags','Strip HTML and PHP tags from a string',1,2),
            'stripcslashes'	=>array('stripcslashes','Un-quote string quoted with addcslashes',1),
            'stripos'		=>array('stripos','Find position of first occurrence of a case-insensitive string',2,3),
            'stripslashes'	=>array('stripslashes','Un-quotes a quoted string',1),
            'stristr'		=>array('stristr','Case-insensitive strstr',2,3),
            'strlen'		=>array('strlen','Get string length',1),
            'strnatcasecmp'	=>array('strnatcasecmp','Case insensitive string comparisons using a "natural order" algorithm',2),
            'strnatcmp'		=>array('strnatcmp','String comparisons using a "natural order" algorithm',2),
            'strncasecmp'	=>array('strncasecmp','Binary safe case-insensitive string comparison of the first n characters',3),
            'strncmp'		=>array('strncmp','Binary safe string comparison of the first n characters',3),
            'strpbrk'		=>array('strpbrk','Search a string for any of a set of characters',2),
            'strpos'		=>array('strpos','Find position of first occurrence of a string',2,3),
            'strrchr'		=>array('strrchr','Find the last occurrence of a character in a string',2),
            'strrev'		=>array('strrev','Reverse a string',1),
            'strripos'		=>array('strripos','Find position of last occurrence of a case-insensitive string in a string',2,3),
            'strrpos'		=>array('strrpos','Find the position of the last occurrence of a substring in a string',2,3),
            'strspn'        =>array('Finds the length of the initial segment of a string consisting entirely of characters contained within a given mask.',2,3,4),
            'strstr'		=>array('strstr','Find first occurrence of a string',2,3),
            'strtolower'	=>array('strtolower','Make a string lowercase',1),
            'strtoupper'	=>array('strtoupper','Make a string uppercase',1),
            'strtr'			=>array('strtr','Translate characters or replace substrings',3),
            'substr_compare'=>array('substr_compare','Binary safe comparison of two strings from an offset, up to length characters',3,4,5),
            'substr_count'	=>array('substr_count','Count the number of substring occurrences',2,3,4),
            'substr_replace'=>array('substr_replace','Replace text within a portion of a string',3,4),
            'substr'		=>array('substr','Return part of a string',2,3),
            'ucfirst'		=>array('ucfirst','Make a string\'s first character uppercase',1),
            'ucwords'		=>array('ucwords','Uppercase the first character of each word in a string',1),

            'stddev'        =>array('stats_standard_deviation','Returns the standard deviation',-1),

            // Locally declared functions
            'if'            => array('exprmgr_if','Excel-style if(test,result_if_true,result_if_false)',3),
            'list'          => array('exprmgr_list','Return comma-separated list of values',-1),
        );

        $this->amVars = array();
        $this->amReservedWords = array();

    }

    /**
     * Add an error to the error log
     *
     * @param <type> $errMsg
     * @param <type> $token
     */
    private function AddError($errMsg, $token)
    {
        $this->errs[] = array($errMsg, $token);
    }

    /**
     * EvalBinary() computes binary expressions, such as (a or b), (c * d), popping  the top two entries off the
     * stack and pushing the result back onto the stack.
     *
     * @param array $token
     * @return boolean - false if there is any error, else true
     */

    private function EvalBinary(array $token)
    {
        if (count($this->stack) < 2)
        {
            $this->AddError("Unable to evaluate binary operator - fewer than 2 entries on stack", $token);
            return false;
        }
        $arg2 = $this->StackPop();
        $arg1 = $this->StackPop();
        if (is_null($arg1) or is_null($arg2))
        {
            $this->AddError("Invalid value(s) on the stack", $token);
            return false;
        }
        // TODO:  try to determine datatype?
        switch(strtolower($token[0]))
        {
            case 'or':
            case '||':
                $result = array(($arg1[0] or $arg2[0]),$token[1],'NUMBER');
                break;
            case 'and':
            case '&&':
                $result = array(($arg1[0] and $arg2[0]),$token[1],'NUMBER');
                break;
            case '==':
            case 'eq':
                $result = array(($arg1[0] == $arg2[0]),$token[1],'NUMBER');
                break;
            case '!=':
            case 'ne':
                $result = array(($arg1[0] != $arg2[0]),$token[1],'NUMBER');
                break;
            case '<':
            case 'lt':
                $result = array(($arg1[0] < $arg2[0]),$token[1],'NUMBER');
                break;
            case '<=';
            case 'le':
                $result = array(($arg1[0] <= $arg2[0]),$token[1],'NUMBER');
                break;
            case '>':
            case 'gt':
                $result = array(($arg1[0] > $arg2[0]),$token[1],'NUMBER');
                break;
            case '>=';
            case 'ge':
                $result = array(($arg1[0] >= $arg2[0]),$token[1],'NUMBER');
                break;
            case '+':
                $result = array(($arg1[0] + $arg2[0]),$token[1],'NUMBER');
                break;
            case '-':
                $result = array(($arg1[0] - $arg2[0]),$token[1],'NUMBER');
                break;
            case '*':
                $result = array(($arg1[0] * $arg2[0]),$token[1],'NUMBER');
                break;
            case '/';
                $result = array(($arg1[0] / $arg2[0]),$token[1],'NUMBER');
                break;
        }
        $this->StackPush($result);
        return true;
    }

    /**
     * Processes operations like +a, -b, !c
     * @param array $token
     * @return boolean - true if success, false if any error occurred
     */

    private function EvalUnary(array $token)
    {
        if (count($this->stack) < 1)
        {
            $this->AddError("Unable to evaluate unary operator - no entries on stack", $token);
            return false;
        }
        $arg1 = $this->StackPop();
        if (is_null($arg1))
        {
            $this->AddError("Invalid value(s) on the stack", $token);
            return false;
        }
        // TODO:  try to determine datatype?
        switch($token[0])
        {
            case '+':
                $result = array((+$arg1[0]),$token[1],'NUMBER');
                break;
            case '-':
                $result = array((-$arg1[0]),$token[1],'NUMBER');
                break;
            case '!';
                $result = array((!$arg[0]),$token[1],'NUMBER');
                break;
        }
        $this->StackPush($result);
        return true;
    }


    /**
     * Main entry function
     * @param <type> $expr
     * @param <type> $onlyparse - if true, then validate the syntax without computing an answer
     * @return boolean - true if success, false if any error occurred
     */

    public function Evaluate($expr, $onlyparse=false)
    {
        $this->expr = $expr;
        $this->tokens = $this->amTokenize($expr);
        $this->count = count($this->tokens);
        $this->pos = -1; // starting position within array (first act will be to increment it)
        $this->errs = array();
        $this->onlyparse = $onlyparse;
        $this->stack = array();
        $this->evalStatus = false;
        $this->result = NULL;
        $this->varsUsed = array();
        $this->reservedWordsUsed = array();

        if ($this->HasSyntaxErrors()) {
            return false;
        }
        else if ($this->EvaluateExpressions())
        {
            if ($this->pos < $this->count)
            {
                $this->AddError("Extra tokens found", $this->tokens[$this->pos]);
                return false;
            }
            $this->result = $this->StackPop();
            if (is_null($this->result))
            {
                return false;
            }
            if (count($this->stack) == 0)
            {
                $this->evalStatus = true;
                return true;
            }
            else
            {
                $this-AddError("Unbalanced equation - values left on stack",NULL);
                return false;
            }
        }
        else
        {
            $this->AddError("Not a valid expression",NULL);
            return false;
        }
    }


    /**
     * Process "a op b" where op in (+,-,concatenate)
     * @return boolean - true if success, false if any error occurred
     */
    private function EvaluateAdditiveExpression()
    {
        if (!$this->EvaluateMultiplicativeExpression())
        {
            return false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            switch ($token[0])
            {
                case '+':
                case '-';
                    if ($this->EvaluateMultiplicativeExpression())
                    {
                        if (!$this->EvalBinary($token))
                        {
                            return false;
                        }
                        // else continue;
                    }
                    else
                    {
                        return false;
                    }
                    break;
                default:
                    --$this->pos;
                    return true;
            }
        }
        return true;
    }

    /**
     * Process a Constant (number of string), retrieve the value of a known variable, or process a function, returning result on the stack.
     * @return boolean - true if success, false if any error occurred
     */

    private function EvaluateConstantVarOrFunction()
    {
        if ($this->pos + 1 >= $this->count)
        {
             $this->AddError("Poorly terminated expression - expected a constant or variable", NULL);
             return false;
        }
        $token = $this->tokens[++$this->pos];
        switch ($token[2])
        {
            case 'NUMBER':
            case 'STRING':
                $this->StackPush($token);
                return true;
                break;
            case 'WORD':
            case 'SGQA':
                if (($this->pos + 1) < $this->count and $this->tokens[($this->pos + 1)][2] == 'LP')
                {
                    return $this->EvaluateFunction();
                }
                else
                {
                    if ($this->isValidVariable($token[0]))
                    {
                        $this->varsUsed[] = $token[0];  // add this variable to list of those used in this equation
                        $result = array($this->amVars[$token[0]],$token[1],'NUMBER');
                        $this->StackPush($result);
                        return true;
                    }
                    else if ($this->isValidReservedWord($token[0]))
                    {
                        $this->reservedWordsUsed[] = $token[0];
                        $result = array($this->amReservedWords[$token[0]],$token[1],'NUMBER');
                        $this->StackPush($result);
                        return true;
                    }
                    else
                    {
                        $this->AddError("Undefined variable or reserved word", $token);
                        return false;
                    }
                }
                break;
            case 'COMMA':
                --$this->pos;
                $this->AddError("Should never  get to this line?",$token);
                return false;
            default:
                return false;
                break;
        }
    }
    
    /**
     * Process "a == b", "a eq b", "a != b", "a ne b"
     * @return boolean - true if success, false if any error occurred
     */
    private function EvaluateEqualityExpression()
    {
        if (!$this->EvaluateRelationExpression())
        {
            return false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            switch (strtolower($token[0]))
            {
                case '==':
                case 'eq':
                case '!=':
                case 'ne':
                    if ($this->EvaluateRelationExpression())
                    {
                        if (!$this->EvalBinary($token))
                        {
                            return false;
                        }
                        // else continue;
                    }
                    else
                    {
                        return false;
                    }
                    break;
                default:
                    --$this->pos;
                    return true;
            }
        }
        return true;
    }

    /**
     * Process a single expression (e.g. without commas)
     * @return boolean - true if success, false if any error occurred
     */

    private function EvaluateExpression()
    {
        if ($this->pos + 2 < $this->count)
        {
            $token1 = $this->tokens[++$this->pos];
            $token2 = $this->tokens[++$this->pos];
            if ($this->isValidVariable($token1[0]) and $token2[2] == 'ASSIGN')
            {
                $evalStatus = $this->EvaluateLogicalOrExpression();
                if ($evalStatus)
                {
                    $result = $this->StackPop();
                    if (!is_null($result))
                    {
                        $newResult = $token2;
                        $newResult[2] = 'NUMBER';
                        $newResult[0] = $this->setVariableValue($token2[0], $token1[0], $result[0]);
                        $this->StackPush($newResult);
                    }
                    else
                    {
                        $evalStatus = false;
                    }
                }
                return $evalStatus;
            }
            else
            {
                // not an assignment expression, so try something else
                $this->pos -= 2;
                return $this->EvaluateLogicalOrExpression();
            }
        }
        else
        {
            return $this->EvaluateLogicalOrExpression();
        }
    }

    /**
     * Process "expression [, expression]*
     * @return boolean - true if success, false if any error occurred
     */

    private function EvaluateExpressions()
    {
        $evalStatus = $this->EvaluateExpression();
        if (!$evalStatus)
        {
            return false;
        }

        while (++$this->pos < $this->count) {  
            $token = $this->tokens[$this->pos];
            if ($token[2] == 'RP')
            {
                return true;    // presumbably the end of an expression
            }
            else if ($token[2] == 'COMMA')
            {
                if ($this->EvaluateExpression())
                {
                    $secondResult = $this->StackPop();
                    $firstResult = $this->StackPop();
                    if (is_null($firstResult))
                    {
                        return false;
                    }
                    $this->StackPush($secondResult);
                    $evalStatus = true;
                }

            }
            else
            {
                $this->AddError("Expected expressions separated by commas",$token);
                $evalStatus = false;
                break;
            }
        }
        while (++$this->pos < $this->count)
        {
            $token = $this->tokens[$this->pos];
            $this->AddError("Extra token found after Expressions",$token);
            $evalStatus = false;
        }
        return $evalStatus;
    }

    /**
     * Process a function call
     * @return boolean - true if success, false if any error occurred
     */
    private function EvaluateFunction()
    {
        $funcNameToken = $this->tokens[$this->pos]; // note that don't need to increment position for functions
        $funcName = $funcNameToken[0];
        if (!$this->isValidFunction($funcName))
        {
            $this->AddError("Undefined Function", $funcNameToken);
            return false;
        }
        $token2 = $this->tokens[++$this->pos];
        if ($token2[2] != 'LP')
        {
            $this->AddError("Expected left parentheses after function name", $token);
        }
        $params = array();  // will just store array of values, not tokens
        while ($this->pos + 1 < $this->count)
        {
            $token3 = $this->tokens[$this->pos + 1];  
            if (count($params) > 0)
            {
                // should have COMMA or RP
                if ($token3[2] == 'COMMA')
                {
                    ++$this->pos;   // consume the token so can process next clause
                    if ($this->EvaluateExpression())
                    {
                        $value = $this->StackPop();
                        if (is_null($value))
                        {
                            return false;
                        }
                        $params[] = $value[0];
                        continue;
                    }
                    else
                    {
                        $this->AddError("Extra comma found in function", $token3);
                        return false;
                    }
                }
            }
            if ($token3[2] == 'RP')
            {
                ++$this->pos;   // consume the token so can process next clause
                return $this->RunFunction($funcNameToken,$params);
            }
            else
            {
                if ($this->EvaluateExpression())
                {
                    $value = $this->StackPop();
                    if (is_null($value))
                    {
                        return false;
                    }
                    $params[] = $value[0];
                    continue;
                }
                else
                {
                    return false;
                }
            }
        }
    }

    /**
     * Process "a && b" or "a and b"
     * @return boolean - true if success, false if any error occurred
     */
    
    private function EvaluateLogicalAndExpression()
    {
        if (!$this->EvaluateEqualityExpression())
        {
            return false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            switch (strtolower($token[0]))
            {
                case '&&':
                case 'and':
                    if ($this->EvaluateEqualityExpression())
                    {
                        if (!$this->EvalBinary($token))
                        {
                            return false;
                        }
                        // else continue
                    }
                    break;
                default:
                    --$this->pos;
                    return true;
            }
        }
        return true;
    }

    /**
     * Process "a || b" or "a or b"
     * @return boolean - true if success, false if any error occurred
     */
    private function EvaluateLogicalOrExpression()
    {
        if (!$this->EvaluateLogicalAndExpression())
        {
            return false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            switch (strtolower($token[0]))
            {
                case '||':
                case 'or':
                    if ($this->EvaluateLogicalAndExpression())
                    {
                        if (!$this->EvalBinary($token))
                        {
                            return false;
                        }
                        // else  continue
                    }
                    else
                    {
                        // an error must have occurred
                        return false;
                    }
                    break;
                default:
                    // no more expressions being  ORed together, so continue parsing
                    --$this->pos;
                    return true;
            }
        }
        // no more tokens to parse
        return true;
    }

    /**
     * Process "a op b" where op in (*,/)
     * @return boolean - true if success, false if any error occurred
     */
    
    private function EvaluateMultiplicativeExpression()
    {
        if (!$this->EvaluateUnaryExpression())
        {
            return  false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            switch ($token[0])
            {
                case '*':
                case '/';
                    if ($this->EvaluateUnaryExpression())
                    {
                        if (!$this->EvalBinary($token))
                        {
                            return false;
                        }
                        // else  continue
                    }
                    else
                    {
                        // an error must have occurred
                        return false;
                    }
                    break;
                    break;
                default:
                    --$this->pos;
                    return true;
            }
        }
        return true;
    }
    
    /**
     * Process expressions including functions and parenthesized blocks
     * @return boolean - true if success, false if any error occurred
     */

    private function EvaluatePrimaryExpression()
    {
        if (($this->pos + 1) >= $this->count) {
            $this->AddError("Poorly terminated expression - expected a constant or variable", NULL);
            return false;
        }
        $token = $this->tokens[++$this->pos];
        if ($token[2] == 'LP')
        {
            if (!$this->EvaluateExpressions())
            {
                return false;
            }
            $token = $this->tokens[$this->pos];
            if ($token[2] == 'RP')
            {
                return true;
            }
            else
            {
                $this->AddError("Expected right parentheses", $token);
                return false;
            }
        }
        else
        {
            --$this->pos;
            return $this->EvaluateConstantVarOrFunction();
        }
    }

    /**
     * Process "a op b" where op in (lt, gt, le, ge, <, >, <=, >=)
     * @return boolean - true if success, false if any error occurred
     */
    private function EvaluateRelationExpression()
    {
        if (!$this->EvaluateAdditiveExpression())
        {
            return false;
        }
        while (($this->pos + 1) < $this->count)
        {
            $token = $this->tokens[++$this->pos];
            switch (strtolower($token[0]))
            {
                case '<':
                case 'lt':
                case '<=';
                case 'le':
                case '>':
                case 'gt':
                case '>=';
                case 'ge':
                    if ($this->EvaluateAdditiveExpression())
                    {
                        if (!$this->EvalBinary($token))
                        {
                            return false;
                        }
                        // else  continue
                    }
                    else
                    {
                        // an error must have occurred
                        return false;
                    }
                    break;
                default:
                    --$this->pos;
                    return true;
            }
        }
        return true;
    }

    /**
     * Process "op a" where op in (+,-,!)
     * @return boolean - true if success, false if any error occurred
     */

    private function EvaluateUnaryExpression()
    {
        if (($this->pos + 1) >= $this->count) {
            $this->AddError("Poorly terminated expression - expected a constant or variable", NULL);
            return false;
        }
        $token = $this->tokens[++$this->pos];
        switch ($token[0])
        {
            case '+':
            case '-':
            case '!':
                if (!$this->EvaluatePrimaryExpression())
                {
                    return false;
                }
                return $this->EvalUnary($token);
                break;
            default:
                --$this->pos;
                return $this->EvaluatePrimaryExpression();
        }
    }

    /**
     * Returns array of all reserved words used when parsing a string via sProcessStringContainingExpressions
     * @return <type>
     */
    
    public function GetAllReservedWordsUsed()
    {
        return array_unique($this->allReservedWordsUsed);
    }

    /**
     * Returns array of all variables used when parsing a string via sProcessStringContainingExpressions
     * @return <type>
     */
    public function GetAllVarsUsed()
    {
        return array_unique($this->allVarsUsed);
    }

    /**
     * Return the result of evaluating the equation - NULL if  error
     * @return mixed
     */
    public function GetResult()
    {
        return $this->result[0];
    }

    /**
     * Return an array of errors
     * @return array
     */
    public function GetErrors()
    {
        return $this->errs;
    }

    /**
     * Return an array of human-readable errors (message, offending token, offset of offending token within equation)
     * @return array
     */
    public function GetReadableErrors()
    {
        // Try to color code the equation
        // Surround whole message with a span, so can add ToolTip of generic error messages
        // Surround each identifiable error with a span so can color code it and attach its own ToolTip (e.g. unknown function or variable name)
        if (count($this->errs) == 0) {
            return '';
        }
        usort($this->errs,"cmpErrorTokens");    // sort errors in order of occurence in string
        $parts = array();
        $curpos = 0;
        $generalErrs = array();
        foreach ($this->errs as $err)
        {
            $token = $err[1];
            if (is_null($token)) {
                if (strlen($err[0]) > 0) {
                    $generalErrs[] = $err[0];
                }
                continue;
            }
            $pos = $token[1];
            $tok =$token[0];
            if (!is_numeric($pos)) {
                if (strlen($err[0]) > 0) {
                    $generalErrs[] = $err[0];
                }
                continue;
            }
            $parts[]= array(
                'OkString' => substr($this->expr,$curpos,($pos-$curpos)),
                'BadString' => $tok,
                'msg' => $err[0]
                );
            $curpos = $pos + strlen($tok);
        }
        if ($curpos < strlen($this->expr)) {
            $parts[] = array(
                'OkString' => substr($this->expr, $curpos, strlen($this->expr) - $curpos),
                'BadString' => '',
                'msg' => ''
            );
        }
        $msg = '';
        $errSpecificStyle= "style='border-style: solid; border-width: 2px; border-color: red;'";
        $errGeneralStyle = "style='background-color: yellow;'";
        foreach ($parts as $part)
        {
            $msg .= $part['OkString'];
            if (isset($part['BadString']) && strlen($part['BadString']) > 0)
            {
                if (strlen($part['msg']) > 0) {
                    $msg .= "<span title='" . $part['msg'] . "' " . $errSpecificStyle . ">" . $part['BadString'] . "</span>";
                }
                else {
                    $msg .= "<span " . $errSpecificStyle . ">" . $part['BadString'] . "</span>";
                }
            }
        }
        $extraErrs = implode("; ", $generalErrs);
        $msg = "<span title='" . $extraErrs . "' " . $errGeneralStyle . ">" . $msg . "</span>";
        return $msg;
    }
    
    /**
     * Return array of list of reserved words used in the equation
     * @return <type> 
     */

    public function GetReservedWordsUsed()
    {
        return array_unique($this->reservedWordsUsed);
    }

    /**
     * Return array of the list of variables used  in the equation
     * @return array
     */
    public function GetVarsUsed()
    {
        return array_unique($this->varsUsed);
    }

    /**
     * Return true if there were syntax or processing errors
     * @return boolean
     */
    public function HasErrors()
    {
        return (count($this->errs) > 0);
    }

    /**
     * Return true if there are syntax errors
     * @return boolean
     */
    private function HasSyntaxErrors()
    {
        // check for bad tokens
        // check for unmatched parentheses
        // check for undefined variables
        // check for undefined functions (but can't easily check allowable # elements?)

        $nesting = 0;

        for ($i=0;$i<$this->count;++$i)
        {
            $token = $this->tokens[$i];
            switch ($token[2])
            {
                case 'LP':
                    ++$nesting;
                    break;
                case 'RP':
                    --$nesting;
                    if ($nesting < 0)
                    {
                        $this->AddError("Extra right parentheses detected", $token);
                    }
                    break;
                case 'WORD':
                case 'SGQA':
                    if ($i+1 < $this->count and $this->tokens[$i+1][2] == 'LP')
                    {
                        if (!$this->isValidFunction($token[0]))
                        {
                            $this->AddError("Undefined function", $token);
                        }
                    }
                    else
                    {
                        if (!($this->isValidVariable($token[0]) or $this->isValidReservedWord($token[0])))
                        {
                            $this->AddError("Undefined variable or reserved word", $token);
                        }
                    }
                    break;
                case 'OTHER':
                    $this->AddError("Unsupported syntax", $token);
                    break;
                default:
                    break;
            }
        }
        if ($nesting != 0)
        {
            $this->AddError("Parentheses not balanced",NULL);
        }
        return (count($this->errs) > 0);
    }

    /**
     * Return true if the function name is registered
     * @param <type> $name
     * @return boolean
     */

    private function isValidFunction($name)
    {
        return array_key_exists($name,$this->amValidFunctions);
    }

    /**
     * Return true if the reserved word name is registered
     * @param <type> $name
     * @return boolean
     */
    private function isValidReservedWord($name)
    {
        return array_key_exists($name,$this->amReservedWords);
    }

    /**
     * Return true if the variable name is registered
     * @param <type> $name
     * @return boolean
     */
    private function isValidVariable($name)
    {
        return array_key_exists($name,$this->amVars);
    }
    
    /**
     * Process a full string, containing multiple expressions delimited by {}, return a consolidated string
     * @param <type> $src 
     */

    public function sProcessStringContainingExpressions($src, $recurseDepth=0)
    {
        // tokenize string by the {} pattern, properly dealing with strings in quotations, and escaped curly brace values
        $stringParts = $this->asSplitStringOnExpressions($src);
        if (count($stringParts) <= 1 or $recurseDepth >= 5) {
            return $src;
        }

        $resolvedParts = array();
        $this->allVarsUsed = array();
        $this->allReservedWordsUsed = array();

        foreach ($stringParts as $stringPart)
        {
            if ($stringPart[2] == 'STRING') {
                $resolvedParts[] =  $stringPart[0];
            }
            else {
                if ($this->Evaluate(substr($stringPart[0],1,-1)))
                {
                    $resolvedParts[] = $this->GetResult();
                    $this->allVarsUsed = array_merge($this->allVarsUsed,$this->GetVarsUsed());
                    $this->allReservedWordsUsed = array_merge($this->allReservedWordsUsed, $this->GetReservedWordsUsed());
                }
                else 
                {
                    // show original and errors in-line
                    $resolvedParts[] = $this->GetReadableErrors();
                }
            }
        }
        $result = implode('',$this->flatten_array($resolvedParts));
        return $result;    // recurse in case there are nested ones, avoiding infinite loops?
    }

    /**
     * Flatten out an array, keeping it in the proper order
     * @param array $a
     * @return array
     */

    private function flatten_array(array $a) {
        $i = 0;
        while ($i < count($a)) {
            if (is_array($a[$i])) {
                array_splice($a, $i, 1, $a[$i]);
            } else {
                $i++;
            }
        }
        return $a;
    }


    /**
     * Run a registered function
     * @param <type> $funcNameToken
     * @param <type> $params
     * @return boolean
     */
    private function RunFunction($funcNameToken,$params)
    {
        $name = $funcNameToken[0];
        if (!$this->isValidFunction($name))
        {
            return false;
        }
        $func = $this->amValidFunctions[$name];
        $funcName = $func[0];
        $numArgs = count($params);

        if (function_exists($funcName)) {
            $numArgsAllowed = array_slice($func, 2);
            $argsPassed = is_array($params) ? count($params) : 0;

            // for unlimited #  parameters
            try
            {
                if (in_array(-1, $numArgsAllowed)) {
                    $result = $funcName($params);

                // Call  function with the params passed
                } elseif (in_array($argsPassed, $numArgsAllowed)) {

                    switch ($argsPassed) {
                    case 0:
                        $result = $funcName();
                        break;
                    case 1:
                        $result = $funcName($params[0]);
                        break;
                    case 2:
                        $result = $funcName($params[0], $params[1]);
                        break;
                    case 3:
                        $result = $funcName($params[0], $params[1], $params[2]);
                        break;
                    case 4:
                        $result = $funcName($params[0], $params[1], $params[2], $params[3]);
                        break;
                    default:
                        $this->AddError("Error: Unsupported arg count: $funcName(".implode(", ",$params),$funcNameToken);
                        return false;
                    }

                } else {
                    $this->AddError("Error: Incorrect arg count: " . $funcName ."(".implode(", ",$params).")",$funcNameToken);
                    return false;
                }
            }
            catch (Exception $e)
            {
                $this->AddError($e->getMessage(),$funcNameToken);
                return false;
            }
            $token = array($result,$funcNameToken[1],'NUMBER');
            $this->StackPush($token);
            return true;
        }
    }

    /**
     * Add user functions to array of allowable functions within the equation.
     * $functions is an array of key to value mappings like this:
     * 'newfunc' => array('my_func_script', 1,3)
     * where 'newfunc' is the name of an allowable function wihtin the  expression, 'my_func_script' is the registered PHP function name,
     * and 1,3 are the list of  allowable numbers of paremeters (so my_func() can take 1 or 3 parameters.
     * 
     * @param array $functions 
     */

    public function RegisterFunctions(array $functions) {
        $this->amValidFunctions= array_merge($this->amValidFunctions, $functions);
    }

    /**
     * Add list of allowable ReservedWord names within the equation
     * $varnames is an array of key to value mappings like this:
     * 'myvar' => value
     * where value is optional (e.g. can be blank), and can be any scalar type (e.g. string, number, but not array)
     * the system will use the values as  fast lookup when doing calculations, but if it needs to set values, it will call
     * the interface function to set the values by name
     *
     * @param array $varnames
     */
    public function RegisterReservedWordsUsingMerge(array $varnames) {
        $this->amReservedWords = array_merge($this->amReservedWords, $varnames);
    }

    public function RegisterReservedWordsUsingReplace(array $varnames) {
        $this->amReservedWords = array_merge(array(), $varnames);
    }

    /**
     * Add list of allowable variable names within the equation
     * $varnames is an array of key to value mappings like this:
     * 'myvar' => value
     * where value is optional (e.g. can be blank), and can be any scalar type (e.g. string, number, but not array)
     * the system will use the values as  fast lookup when doing calculations, but if it needs to set values, it will call
     * the interface function to set the values by name
     *
     * @param array $varnames
     */
    public function RegisterVarnamesUsingMerge(array $varnames) {
        $this->amVars = array_merge($this->amVars, $varnames);
    }

    public function RegisterVarnamesUsingReplace(array $varnames) {
        $this->amVars = array_merge(array(), $varnames);
    }

    /**
     * Set the value of a registered variable
     * @param $op - the operator (=,*=,/=,+=,-=)
     * @param <type> $name
     * @param <type> $value
     */
    private function setVariableValue($op,$name,$value)
    {
        // TODO - set this externally
        switch($op)
        {
            case '=':
                $this->amVars[$name] = $value;
                break;
            case '*=':
                $this->amVars[$name] *= $value;
                break;
            case '/=':
                $this->amVars[$name] /= $value;
                break;
            case '+=':
                $this->amVars[$name] += $value;
                break;
            case '-=':
                $this->amVars[$name] -= $value;
                break;
        }
        return $this->amVars[$name];
    }

    public function asSplitStringOnExpressions($src)
    {
        // tokenize string by the {} pattern, propertly dealing with strings in quotations, and escaped curly brace values
        $tokens0 = preg_split($this->sExpressionRegex,$src,-1,(PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE));

        $tokens = array();
        // Add token_type to $tokens:  For each token, test each categorization in order - first match will be the best.
        for ($j=0;$j<count($tokens0);++$j)
        {
            $token = $tokens0[$j];
            if (preg_match($this->sExpressionRegex,$token[0]))
            {
                $token[2] = 'EXPRESSION';
            }
            else
            {
                $token[2] = 'STRING';
            }
            $tokens[] = $token;
        }
        return $tokens;
    }

    /**
     * Pop a value token off of the stack
     * @return token
     */

    private function StackPop()
    {
        if (count($this->stack) > 0)
        {
            return array_pop($this->stack);
        }
        else
        {
            $this->AddError("Tried to pop value off of empty stack", NULL);
            return NULL;
        }
    }

    /**
     * Stack only holds values (number, string), not operators
     * @param array $token
     */

    private function StackPush(array $token)
    {
        if ($this->onlyparse)
        {
            // If only parsing, still want to validate syntax, so use "1" for all variables
            switch($token[2])
            {
                case 'STRING':
                    $this->stack[] = array(1,$token[1],'STRING');
                    break;
                case 'NUMBER':
                default:
                    $this->stack[] = array(1,$token[1],'NUMBER');
                    break;
            }
        }
        else
        {
            $this->stack[] = $token;
        }
    }

    /**
     * Split the source string into tokens, removing whitespace, and categorizing them by type.
     *
     * @param $src
     * @return array
     */

    private function amTokenize($src)
    {
        // $tokens0 = array of tokens from equation, showing value and offset position.  Will include SPACE, which should be removed
        $tokens0 = preg_split($this->sTokenizerRegex,$src,-1,(PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE));

        // $tokens = array of tokens from equation, showing value, offsete position, and type.  Will not contain SPACE, but will contain OTHER
        $tokens = array();
        // Add token_type to $tokens:  For each token, test each categorization in order - first match will be the best.
        for ($j=0;$j<count($tokens0);++$j)
        {
            for ($i=0;$i<count($this->asCategorizeTokensRegex);++$i)
            {
                $token = $tokens0[$j][0];
                if (preg_match($this->asCategorizeTokensRegex[$i],$token))
                {
                    if ($this->asTokenType[$i] !== 'SPACE') {
                        $tokens0[$j][2] = $this->asTokenType[$i];
                        if ($this->asTokenType[$i] == 'STRING')
                        {
                            // remove outside quotes
                            $unquotedToken = stripslashes(substr($token,1,-1));
                            $tokens0[$j][0] = $unquotedToken;
                        }
                        $tokens[] = $tokens0[$j];   // get first matching non-SPACE token type and push onto $tokens array
                    }
                    break;  // only get first matching token type
                }
            }
        }
        return $tokens;
    }

    /**
     * Unit test the asSplitStringOnExpressions() function to ensure that accurately parses out all expressions
     * surrounded by curly braces, allowing for strings and escaped curly braces.
     */

    static function UnitTestStringSplitter()
    {
       $tests = <<<EOD
"this is a string that contains {something in curly braces)"
This example has escaped curly braces like \{this is not an equation\}
Should the parser check for unmatched { opening curly braces?
What about for unmatched } closing curly braces?
{ANS:name}, you said that you are {ANS:age} years old, and that you have {ANS:numKids} {EVAL:if((numKids==1),'child','children')} and {ANS:numPets} {EVAL:if((numPets==1),'pet','pets')} running around the house. So, you have {EVAL:numKids + numPets} wild {EVAL:if((numKids + numPets ==1),'beast','beasts')} to chase around every day.
Since you have more {EVAL:if((INSERTANS:61764X1X3 > INSERTANS:61764X1X4),'children','pets')} than you do {EVAL:if((INSERTANS:61764X1X3 > INSERTANS:61764X1X4),'pets','children')}, do you feel that the {EVAL:if((INSERTANS:61764X1X3 > INSERTANS:61764X1X4),'pets','children')} are at a disadvantage?
EOD;

        $em = new ExpressionManager();

        foreach(explode("\n",$tests) as $test)
        {
            $tokens = $em->asSplitStringOnExpressions($test);
            print '<b>' . $test . '</b><hr/>';
            print '<code>';
            print implode("<br/>\n",explode("\n",print_r($tokens,TRUE)));
            print '</code><hr/>';
        }
    }

    /**
     * Unit test the Tokenizer - Tokenize and generate a HTML-compatible print-out of a comprehensive set of test cases
     */

    static function UnitTestTokenizer()
    {
        // Comprehensive test cases for tokenizing
        $tests = <<<EOD
        String:  "Can strings contain embedded \"quoted passages\" (and parentheses + other characters?)?"
        String:  "can single quoted strings" . 'contain nested \'quoted sections\'?';
        Parens:  upcase('hello');
        Numbers:  42 72.35 -15 +37 42A .5 0.7
        And_Or: (this and that or the other);  Sandles, sorting; (a && b || c)
        Words:  hi there, my name is C3PO!
        UnaryOps: ++a, --b !b
        BinaryOps:  (a + b * c / d)
        Comparators:  > >= < <= == != gt ge lt le eq ne (target large gents built agile less equal)
        Assign:  = += -= *= /=
        SGQA:  1X6X12 1X6X12ber1 1X6X12ber1_lab1 3583X84X249
        Errors: Apt # 10C; (2 > 0) ? 'hi' : 'there'; array[30]; >>> <<< /* this is not a comment */ // neither is this
EOD;

        $em = new ExpressionManager();

        foreach(explode("\n",$tests) as $test)
        {
            $tokens = $em->amTokenize($test);
            print '<b>' . $test . '</b><hr/>';
            print '<code>';
            print implode("<br/>\n",explode("\n",print_r($tokens,TRUE)));
            print '</code><hr/>';
        }
    }

    /**
     * Unit test the Evaluator, allowing for passing in of extra functions, variables, and tests
     * @param array $extraFunctions
     * @param array $extraVars
     * @param <type> $extraTests
     */
    
    static function UnitTestEvaluator(array $extraFunctions=array(), array $extraVars=array(), $extraTests='1~1')
    {
        // Some test cases for Evaluator
        $vars = array(
            'one'		=>1,
            'two'		=>2,
            'three'		=>3,
            'four'		=>4,
            'five'		=>5,
            'six'		=>6,
            'seven'     =>7,
            'eight'     =>8,
            'nine'      =>9,
            'ten'       =>10,
            'eleven'  => 11,
            'twelve'   => 12,       
            'half'      =>.5,
            'hi'        =>'there',
            'hello' 	=>"Tom",
            'a'         =>0,
            'b'         =>0,
            'c'         =>0,
            'd'         =>0,
            '12X34X56'  =>5,
            '12X3X5lab1_ber'    =>10,
            'q5pointChoice.code'    =>5,
            'q5pointChoice.value'   => 'Father',
            'qArrayNumbers.ls1.min.code'    => 7,
            'qArrayNumbers.ls1.min.value' => 'I love LimeSurvey',
            '12X3X5lab1_ber#2'  => 15,
        );

        $reservedWord = array(
            'ADMINEMAIL'					=>'{ADMINEMAIL}',
            'ADMINNAME'						=>'{ADMINNAME}',
            'AID'							=>'{AID}',
            'ANSWERSCLEARED'				=>'{ANSWERSCLEARED}',
            'ANSWER'						=>'{ANSWER}',
            'ASSESSMENTS'					=>'{ASSESSMENTS}',
            'ASSESSMENT_CURRENT_TOTAL'		=>'{ASSESSMENT_CURRENT_TOTAL}',
            'ASSESSMENT_HEADING'			=>'{ASSESSMENT_HEADING}',
            'CHECKJAVASCRIPT'				=>'{CHECKJAVASCRIPT}',
            'CLEARALL'						=>'{CLEARALL}',
            'CLOSEWINDOW'					=>'{CLOSEWINDOW}',
            'COMPLETED'						=>'{COMPLETED}',
            'DATESTAMP'						=>'{DATESTAMP}',
            'EMAILCOUNT'					=>'{EMAILCOUNT}',
            'EMAIL'							=>'{EMAIL}',
            'EXPIRY'						=>'{EXPIRY}',
            'FIRSTNAME'						=>'{FIRSTNAME}',
            'GID'							=>'{GID}',
            'GROUPDESCRIPTION'				=>'{GROUPDESCRIPTION}',
            'GROUPNAME'						=>'{GROUPNAME}',
            'INSERTANS:123X45X67'			=>'{INSERTANS:123X45X67}',
            'INSERTANS:123X45X67ber'		=>'{INSERTANS:123X45X67ber}',
            'INSERTANS:123X45X67ber_01a'	=>'{INSERTANS:123X45X67ber_01a}',
            'LANGUAGECHANGER'				=>'{LANGUAGECHANGER}',
            'LANGUAGE'						=>'{LANGUAGE}',
            'LANG'							=>'{LANG}',
            'LASTNAME'						=>'{LASTNAME}',
            'LOADERROR'						=>'{LOADERROR}',
            'LOADFORM'						=>'{LOADFORM}',
            'LOADHEADING'					=>'{LOADHEADING}',
            'LOADMESSAGE'					=>'{LOADMESSAGE}',
            'NAME'							=>'{NAME}',
            'NAVIGATOR'						=>'{NAVIGATOR}',
            'NOSURVEYID'					=>'{NOSURVEYID}',
            'NOTEMPTY'						=>'{NOTEMPTY}',
            'NULL'							=>'{NULL}',
            'NUMBEROFQUESTIONS'				=>'{NUMBEROFQUESTIONS}',
            'OPTOUTURL'						=>'{OPTOUTURL}',
            'PASSTHRULABEL'					=>'{PASSTHRULABEL}',
            'PASSTHRUVALUE'					=>'{PASSTHRUVALUE}',
            'PERCENTCOMPLETE'				=>'{PERCENTCOMPLETE}',
            'PERC'							=>'{PERC}',
            'PRIVACYMESSAGE'				=>'{PRIVACYMESSAGE}',
            'PRIVACY'						=>'{PRIVACY}',
            'QID'							=>'{QID}',
            'QUESTIONHELPPLAINTEXT'			=>'{QUESTIONHELPPLAINTEXT}',
            'QUESTIONHELP'					=>'{QUESTIONHELP}',
            'QUESTION_CLASS'				=>'{QUESTION_CLASS}',
            'QUESTION_CODE'					=>'{QUESTION_CODE}',
            'QUESTION_ESSENTIALS'			=>'{QUESTION_ESSENTIALS}',
            'QUESTION_FILE_VALID_MESSAGE'	=>'{QUESTION_FILE_VALID_MESSAGE}',
            'QUESTION_HELP'					=>'{QUESTION_HELP}',
            'QUESTION_INPUT_ERROR_CLASS'	=>'{QUESTION_INPUT_ERROR_CLASS}',
            'QUESTION_MANDATORY'			=>'{QUESTION_MANDATORY}',
            'QUESTION_MAN_CLASS'			=>'{QUESTION_MAN_CLASS}',
            'QUESTION_MAN_MESSAGE'			=>'{QUESTION_MAN_MESSAGE}',
            'QUESTION_NUMBER'				=>'{QUESTION_NUMBER}',
            'QUESTION_TEXT'					=>'{QUESTION_TEXT}',
            'QUESTION_VALID_MESSAGE'		=>'{QUESTION_VALID_MESSAGE}',
            'QUESTION'						=>'{QUESTION}',
            'REGISTERERROR'					=>'{REGISTERERROR}',
            'REGISTERFORM'					=>'{REGISTERFORM}',
            'REGISTERMESSAGE1'				=>'{REGISTERMESSAGE1}',
            'REGISTERMESSAGE2'				=>'{REGISTERMESSAGE2}',
            'RESTART'						=>'{RESTART}',
            'RETURNTOSURVEY'				=>'{RETURNTOSURVEY}',
            'SAVEALERT'						=>'{SAVEALERT}',
            'SAVEDID'						=>'{SAVEDID}',
            'SAVEERROR'						=>'{SAVEERROR}',
            'SAVEFORM'						=>'{SAVEFORM}',
            'SAVEHEADING'					=>'{SAVEHEADING}',
            'SAVEMESSAGE'					=>'{SAVEMESSAGE}',
            'SAVE'							=>'{SAVE}',
            'SGQ'							=>'{SGQ}',
            'SID'							=>'{SID}',
            'SITENAME'						=>'{SITENAME}',
            'SUBMITBUTTON'					=>'{SUBMITBUTTON}',
            'SUBMITCOMPLETE'				=>'{SUBMITCOMPLETE}',
            'SUBMITREVIEW'					=>'{SUBMITREVIEW}',
            'SURVEYCONTACT'					=>'{SURVEYCONTACT}',
            'SURVEYDESCRIPTION'				=>'{SURVEYDESCRIPTION}',
            'SURVEYFORMAT'					=>'{SURVEYFORMAT}',
            'SURVEYLANGAGE'					=>'{SURVEYLANGAGE}',
            'SURVEYLISTHEADING'				=>'{SURVEYLISTHEADING}',
            'SURVEYLIST'					=>'{SURVEYLIST}',
            'SURVEYNAME'					=>'{SURVEYNAME}',
            'SURVEYURL'						=>'{SURVEYURL}',
            'TEMPLATECSS'					=>'{TEMPLATECSS}',
            'TEMPLATEURL'					=>'{TEMPLATEURL}',
            'TEXT'							=>'{TEXT}',
            'THEREAREXQUESTIONS'			=>'{THEREAREXQUESTIONS}',
            'TIME'							=>'{TIME}',
            'TOKEN:EMAIL'					=>'{TOKEN:EMAIL}',
            'TOKEN:FIRSTNAME'				=>'{TOKEN:FIRSTNAME}',
            'TOKEN:LASTNAME'				=>'{TOKEN:LASTNAME}',
            'TOKEN:XXX'						=>'{TOKEN:XXX}',
            'TOKENCOUNT'					=>'{TOKENCOUNT}',
            'TOKEN_COUNTER'					=>'{TOKEN_COUNTER}',
            'TOKEN'							=>'{TOKEN}',
            'URL'							=>'{URL}',
            'WELCOME'						=>'{WELCOME}',
        );

        // Syntax for $tests is~
        // expectedResult~expression
        // if the expected result is an error, use NULL for the expected result
        $tests  = <<<EOD
50~12X34X56 * 12X3X5lab1_ber
3~a=three
3~c=a
12~c*=four
15~c+=a
5~c/=a
-1~c-=six
2~max(one,two)
5~max(one,two,three,four,five)
1024~max(one,(two*three),pow(four,five),six)
1~min(one,two,three,four,five)
27~pow(3,3)
5~hypot(three,four)
0~0
24~one * two * three * four
-4~five - four - three - two
0~two * three - two - two - two
4~two * three - two
3.1415926535898~pi()
1~pi() == pi() * 2 - pi()
1~sin(pi()/2)
1~sin(0.5 * pi())
1~sin(pi()/2) == sin(.5 * pi())
105~5 + 1, 7 * 15
7~7
15~10 + 5
24~12 * 2
10~13 - 3
3.5~14 / 4
5~3 + 1 * 2
1~one
there~hi
6.25~one * two - three / four + five
1~one + hi
1~two > one
1~two gt one
1~three >= two
1~three ge  two
0~four < three
0~four lt three
0~four <= three
0~four le three
0~four == three
0~four eq three
1~four != three
0~four ne four
0~one * hi
5~abs(-five)
0~acos(pi()/2)
0~asin(pi()/2)
10~ceil(9.1)
9~floor(9.9)
32767~getrandmax()
0~rand()
15~sum(one,two,three,four,five)
5~intval(5.7)
1~is_float('5.5')
0~is_float('5')
1~is_numeric(five)
0~is_numeric(hi)
1~is_string(hi)
2.4~(one  * two) + (three * four) / (five * six)
1~(one * (two + (three - four) + five) / six)
0~one && 0
0~two and 0
1~five && 6
1~seven && eight
1~one or 0
1~one || 0
1~(one and 0) || (two and three)
NULL~hi(there);
NULL~(one * two + (three - four)
NULL~(one * two + (three - four)))
NULL~++a
NULL~--b
11~eleven
144~twelve * twelve
4~if(5 > 7,2,4)
there~if((one > two),'hi','there')
64~if((one < two),pow(2,6),pow(6,2))
1,2,3,4,5~list(one,two,three,min(four,five,six),max(three,four,five))
11,12~list(eleven,twelve)
{INSERTANS:123X45X67}~INSERTANS:123X45X67
{QID}~QID
{ASSESSMENT_HEADING}~ASSESSMENT_HEADING
{TOKEN:FIRSTNAME}~TOKEN:FIRSTNAME
{THEREAREXQUESTIONS}~THEREAREXQUESTIONS
5~q5pointChoice.code
Father~q5pointChoice.value
7~qArrayNumbers.ls1.min.code
I love LimeSurvey~qArrayNumbers.ls1.min.value
15~12X3X5lab1_ber#2
EOD;
        
        $em = new ExpressionManager();
        $em->RegisterVarnamesUsingMerge($vars);
        $em->RegisterReservedWordsUsingMerge($reservedWord);

        if (is_array($extraVars) and count($extraVars) > 0)
        {
            $em->RegisterVarnamesUsingMerge($extraVars);
        }
        if (is_array($extraFunctions) and count($extraFunctions) > 0)
        {
            $em->RegisterFunctions($extraFunctions);
        }
        if (is_string($extraTests))
        {
            $tests .= "\n" . $extraTests;
        }

        print '<table border="1"><tr><th>Expression</th><th>Result</th><th>Expected</th><th>VarsUsed</th><th>ReservedWordsUsed</th><th>Errors</th></tr>';
        foreach(explode("\n",$tests)as $test)
        {
            $values = explode("~",$test);
            $expectedResult = array_shift($values);
            $expr = implode("~",$values);
            $resultStatus = 'ok';
            print '<tr><td>' . $expr . "</td>\n";
            $status = $em->Evaluate($expr);
            $result = $em->GetResult();
            $valToShow = $result;
            if (is_null($result)) {
                $valToShow = "NULL";
            }
            print '<td>' . $valToShow . "</td>\n";
            if ($valToShow != $expectedResult)
            {
                $resultStatus = 'error';
            }
            print "<td class='" . $resultStatus . "'>" . $expectedResult . "</td>\n";
            $varsUsed = $em->GetVarsUsed();
            if (is_array($varsUsed) and count($varsUsed) > 0) {
                print '<td>' . implode(', ', $varsUsed) . "</td>\n";
            }
            else {
                print "<td>&nbsp;</td>\n";
            }
            $reservedWordsUsed = $em->GetReservedWordsUsed();
            if (is_array($reservedWordsUsed) and count($reservedWordsUsed) > 0) {
                print '<td>' . implode(', ', $reservedWordsUsed) . "</td>\n";
            }
            else {
                print "<td>&nbsp;</td>\n";
            }
            $errString = $em->GetReadableErrors();
            if (strlen($errString) > 0) {
                print "<td>" . $errString . "</td>\n";
            }
            else {
                print "<td>&nbsp;</td>\n";
            }
            print '</tr>';
        }
        print '</table>';
    }

    static function UnitTestProcessStringContainingExpressions()
    {
        $vars = array(
            'name'      => 'Sergei',
            'age'       => 45,
            'numKids'   => 2,
            'numPets'   => 1,
        );
        $reservedWords = array(
            'INSERTANS:61764X1X1'   => 'Peter',
            'INSERTANS:61764X1X2'   => 27,
            'INSERTANS:61764X1X3'   => 1,
            'INSERTANS:61764X1X4'   => 8
        );

        $tests = <<<EOD
{name}, you said that you are {age} years old, and that you have {numKids} {if((numKids==1),'child','children')} and {numPets} {if((numPets==1),'pet','pets')} running around the house. So, you have {numKids + numPets} wild {if((numKids + numPets ==1),'beast','beasts')} to chase around every day.
Since you have more {if((numKids > numPets),'children','pets')} than you do {if((numKids > numPets),'pets','children')}, do you feel that the {if((numKids > numPets),'pets','children')} are at a disadvantage?
{INSERTANS:61764X1X1}, you said that you are {INSERTANS:61764X1X2} years old, and that you have {INSERTANS:61764X1X3} {if((INSERTANS:61764X1X3==1),'child','children')} and {INSERTANS:61764X1X4} {if((INSERTANS:61764X1X4==1),'pet','pets')} running around the house.  So, you have {INSERTANS:61764X1X3 + INSERTANS:61764X1X4} wild {if((INSERTANS:61764X1X3 + INSERTANS:61764X1X4 ==1),'beast','beasts')} to chase around every day.
Since you have more {if((INSERTANS:61764X1X3 > INSERTANS:61764X1X4),'children','pets')} than you do {if((INSERTANS:61764X1X3 > INSERTANS:61764X1X4),'pets','children')}, do you feel that the {if((INSERTANS:61764X1X3 > INSERTANS:61764X1X4),'pets','children')} are at a disadvantage?
EOD;

        $em = new ExpressionManager();
        $em->RegisterVarnamesUsingMerge($vars);
        $em->RegisterReservedWordsUsingMerge($reservedWords);

        print '<table border="1"><tr><th>Test</th><th>Result</th><th>VarsUsed</th><th>ReservedWordsUsed</th></tr>';
        foreach(explode("\n",$tests) as $test)
        {
            print "<tr><td>" . $test . "</td>\n";
            print "<td>" . $em->sProcessStringContainingExpressions($test) . "</td>\n";
            $allVarsUsed = $em->getAllVarsUsed();
            if (is_array($allVarsUsed) and count($allVarsUsed) > 0) {
                print "<td>" . implode(', ', $allVarsUsed) . "</td>\n";
            }
            else {
                print "<td>&nbsp;</td>\n";
            }
            $allReservedWordsUsed = $em->getAllReservedWordsUsed();
            if (is_array($allReservedWordsUsed) and count($allReservedWordsUsed) > 0) {
                print "<td>" . implode(', ', $allReservedWordsUsed) . "</td>\n";
            }
            else {
                print "<td>&nbsp;</td>\n";
            }
            print "</tr>\n";
        }
        print '</table>';
    }
}

/*
 * Extra Functions can  go here.  TODO:  Find good way to inlcude these extra functions externally.
 * Tried via ExpressionManagerFunctions, but they weren't properly included in dFunctionEval.php
 */

function exprmgr_if($test,$ok,$error)
{
    if ($test)
    {
        return $ok;
    }
    else
    {
        return $error;
    }
}

function exprmgr_list($args)
{
    return implode(", ",$args);
}

/**
 * Used by usort() to order Error tokens by their position within the string
 * @param <type> $a
 * @param <type> $b
 * @return <type>
 */
function cmpErrorTokens($a, $b)
{
    if (is_null($a[1])) {
        if (is_null($b[1])) {
            return 0;
        }
        return 1;
    }
    if (is_null($b[1])) {
        return -1;
    }
    if ($a[1][1] == $b[1][1]) {
        return 0;
    }
    return ($a[1][1] < $b[1][1]) ? -1 : 1;
}

?>
