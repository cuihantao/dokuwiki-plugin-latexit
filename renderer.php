<?php

/**
 * DokuWiki Plugin latexit (Renderer Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Adam Kučera <adam.kucera@wrent.cz>
 */
// must be run within Dokuwiki
if (!defined('DOKU_INC'))
    die();

/**
 * Latexit plugin extends class in this file
 */
require_once DOKU_INC . 'inc/parser/renderer.php';

/**
 * includes additional plugin classes
 */
require_once DOKU_INC . 'lib/plugins/latexit/classes/Package.php';
require_once DOKU_INC . 'lib/plugins/latexit/classes/RowspanHandler.php';

/**
 * includes default DocuWiki files containing functions used by latexit plugin
 */
require_once DOKU_INC . 'inc/parserutils.php';
require_once DOKU_INC . 'inc/pageutils.php';
require_once DOKU_INC . 'inc/pluginutils.php';

/**
 * Main latexit class, specifies how will be latex rendered
 */
class renderer_plugin_latexit extends Doku_Renderer {

    /**
     * stores all required LaTeX packages
     * @var array 
     */
    private $packages;

    /**
     * Stores the information about last list level
     * @var int
     */
    private $last_level;

    /**
     * Is true when the renderer is in a list
     * @var boolean
     */
    private $list_opened;

    /**
     * Stores the information about the level of recursion.
     * It stores the depth of current recusively added file.
     * @var int
     */
    private $recursion_level;

    /**
     * Used in recursively inserted files, stores information about headers level.
     * @var int
     */
    private $headers_level;

    /**
     * FIXME configurable
     * Is TRUE when recursive inserting should be used.
     * @var bool
     */
    private $recursive;

    /**
     * Stores the information about the headers level increase in last recursive insertion.
     * @var int
     */
    private $last_level_increase;

    /**
     * Stores the information about the number of cells found in a table row.
     * @var int
     */
    private $cells_count;

    /**
     * Stores the information about the number a table cols.
     * @var int
     */
    private $table_cols;

    /**
     * Stores the last colspan in a table.
     * @var int
     */
    private $last_colspan;

    /**
     * Stores the last rowspan in a table.
     * @var int
     */
    private $last_rowspan;

    /**
     * Stores the last align of a cell in a table.
     * @var int
     */
    private $last_align;

    /**
     * Is TRUE when renderer is inside a table.
     * @var bool
     */
    private $in_table;

    /**
     * FIXME conf
     * Stores the default table align
     * @var string
     */
    private $default_table_align;

    /**
     * An instance of a RowspanHandler class.
     * @var RowspanHandler
     */
    private $rowspan_handler;

    /**
     * Make available as LaTeX renderer
     */
    public function canRender($format) {
        if ($format == 'latex') {
            return true;
        }
        return false;
    }

    /**
     * Return the rendering format of the renderer - latex
     */
    public function getFormat() {
        return 'latex';
    }

    /**
     * Renderer is always created as a new instance.
     */
    public function isSingleton() {
        return false;
    }

    /**
     * function is called, when a document is started to being rendered.
     * It inicializes variables, adds headers to the LaTeX document and
     * sets the browser headers of the exported file.
     */
    function document_start() {
        //register global variables used for recursive rendering
        global $latexit_level;
        global $latexit_headers;

        //initialize variables
        $this->packages = array();
        $this->list_opened = FALSE;
        $this->recursive = FALSE;
        $this->in_table = FALSE;
        $this->last_level_increase = 0;
        $this->rowspan_handler = new RowspanHandler();
        //FIXME v konfiguraci nastavit defaultni zarovnani tabulek (zvysi pak prehlednost generovaneho kodu)
        $this->default_table_align = 'l';

        if (!isset($latexit_level) || is_null($latexit_level)) {
            $this->recursion_level = 0;
        } else {
            $this->recursion_level = $latexit_level;
        }
        if (!isset($latexit_headers) || is_null($latexit_headers)) {
            $this->headers_level = 0;
        } else {
            $this->headers_level = $latexit_headers;
        }

        //FIXME nastavit nejak hlavni title dokumentu podle title stranky?
        //this tag will be replaced in the end, all required packages will be added
        $packages = '~~~PACKAGES~~~';
        if (!$this->_immersed()) {
            //document is MAIN PAGE of exported file
            //this is default LaTeX header right now, can be changed in configuration
            $header_default = "\\documentclass[a4paper, oneside, 10pt]{memoir}\n"
                    . "\\usepackage[utf8x]{inputenc}\n"
                    . "\\usepackage[table]{xcolor}\n"
                    . "\\usepackage{czech}\n";

            $document_start = "\\begin{document}";
            //FIXME if conf
            $header = $header_default;
            $this->doc .= $header . $packages . $document_start;
            $this->doc .= "\n\n";

            //set the headers, so the browsers knows, this is not the HTML file
            header('Content-Type: application/x-latex');
            $filename = "output" . time() . ".latex";
            header("Content-Disposition: attachment; filename='$filename';");
        } else {
            //document is RECURSIVELY added file to another file
            $this->doc .= '~~~PACKAGES-START~~~';
            $this->doc .= $packages;
            $this->doc .= '~~~PACKAGES-END~~~';
        }
    }

    /**
     * function is called, when a document ends its rendering to finish the document
     * It finalizes the document.
     */
    function document_end() {
        if (!$this->_immersed()) {
            //this is MAIN PAGE of exported file, we can finalize document
            $this->doc .= "\n\n";
            $footer_default = "\\end{document}\n";
            //FIXME if conf footer
            $this->doc .= $footer_default;

            //finalize rendering of few entities
            $this->_highlightFixme();
            $this->_removeEntities();
        }
        //insert all packages collected during rendering as \usepackage
        $this->_insertPackages();
    }

    //FIXME muze vlozit latex obsah, ale nejspis jen podle nastaveni v konfiguraci
    function render_TOC() {
        return '';
    }

    //FIXME
    function toc_additem($id, $text, $level) {
        
    }

    /**
     * Function is called, when renderer finds a new header.
     * It calls the LaTeX command for an appropriate level.
     * @param string $text Text of the header
     * @param int $level Level of the header.
     * @param int $pos Not used in LaTeX
     */
    function header($text, $level, $pos) {

        if ($this->_immersed()) {
            //when document is recursively inserted, it will continue from previous headers level
            $level += $this->headers_level;
        }
        $this->doc .= "\n\n";
        switch ($level) {
            //FIXME zakladni level headeru bude konfigurovatelny a bude odpovidat typu dokumentu
            case 1:
                $this->_header('section', $text);
                break;
            case 2:
                $this->_header('subsection', $text);
                break;
            case 3:
                $this->_header('subsubsection', $text);
                break;
            case 4:
                $this->_header('paragraph', $text);
                break;
            case 5:
                $this->_header('subparagraph', $text);
                break;
            default:
                $this->_open('textbf');
                $this->doc .= $this->_latexSpecialChars($text);
                $this->_close();
                $this->doc .= "\n";
                break;
        }
        //add label so each section can be referenced
        $this->doc .= "\label{".$this->_createLabel($text)."}";
    }
 
   /**
     * Basic funcion called, when a text not from DokuWiki syntax is read
     * It adds the data to the document, potentionally dangerous characters for
     * LaTeX are escaped or removed.
     * @param string $text Text to be inserted.
     */
    function cdata($text) {
        $this->doc .= $this->_latexSpecialChars($text);
    }

    /**
     * Function is called, when renderer finds a new paragraph.
     * It makes new paragraph in LaTeX Document.
     */
    function p_open() {
        $this->doc .= "\n\n";
    }

    /**
     * Function is called, when renderer finds a linebreak.
     * It adds new line in LaTeX Document.
     */
    function linebreak() {
        if ($this->in_table) {
            //in tables in LaTeX there is different syntax
            $this->doc .= "\\newline ";
        } else {
            $this->doc .= "\\\\";
        }
    }

    /**
     * Function is called, when renderer finds a horizontal line.
     * It adds centered horizontal line in LaTeX Document.
     */
    function hr() {
        $this->doc .= "\n\n\begin{center}\n";
        $this->doc .= "\line(1,0){250}\n";
        $this->doc .= '\end{center}';
        $this->doc .= "\n\n";
    }

    /**
     * function is called, when renderer finds a strong text
     * It calls command for strong text in LaTeX Document.
     */
    function strong_open() {
        $this->_open('textbf');
    }

    /**
     * function is called, when renderer finds the end of a strong text 
     */
    function strong_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds an emphasised text
     * It calls command for emphasised text in LaTeX Document.
     */
    function emphasis_open() {
        $this->_open('emph');
    }

    /**
     * function is called, when renderer finds the end of an emphasised text
     */
    function emphasis_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds an underlined text
     * It calls command for underlined text in LaTeX Document.
     */
    function underline_open() {
        $this->_open('underline');
    }

    /**
     * function is called, when renderer finds the end of an underlined text
     */
    function underline_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds a monospace text 
     * (all letters have same width)
     * It calls command for monospace text in LaTeX Document.
     */
    function monospace_open() {
        $this->_open('texttt');
    }

    /**
     * function is called, when renderer finds the end of a monospace text 
     * (all letters have same width)
     */
    function monospace_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds a subscript 
     * It adds needed package and calls command for subscript in LaTeX Document.
     */
    function subscript_open() {
        $package = new Package('fixltx2e');
        $this->_addPackage($package);
        $this->_open('textsubscript');
    }

    /**
     * function is called, when renderer finds the end of a subscript 
     */
    function subscript_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds a superscript 
     * It adds needed package and calls command for superscript in LaTeX Document.
     */
    function superscript_open() {
        $package = new Package('fixltx2e');
        $this->_addPackage($package);
        $this->_open('textsuperscript');
    }

    /**
     * function is called, when renderer finds the end of a superscript 
     */
    function superscript_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds a deleted text
     * It adds needed package and calls command for deleted text in LaTeX Document.
     */
    function deleted_open() {
        $package = new Package('ulem');
        $package->addParameter('normalem');
        $this->_addPackage($package);
        $this->_open('sout');
    }

    /**
     * function is called, when renderer finds the end of a deleted text
     */
    function deleted_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds a footnote
     * It calls footnote command in LaTeX Document.
     */
    function footnote_open() {
        $this->_open('footnote');
    }

    /**
     * function is called, when renderer finds the end of a footnote
     */
    function footnote_close() {
        $this->_close();
    }

    /**
     * function is called, when renderer finds start of an unordered list
     * It calls command for an unordered list in latex, even with right indention
     */
    function listu_open() {
        $this->_list_open("itemize");
    }

    /**
     * function is called, when renderer finds the end of an unordered list
     * It calls command for the end of an unordered list in latex, even with right indention
     */
    function listu_close() {
        $this->_list_close("itemize");
    }

    /**
     * function is called, when renderer finds start of an ordered list
     * It calls command for an ordered list in latex, even with right indention
     */
    function listo_open() {
        $this->_list_open("enumerate");
    }

    /**
     * function is called, when renderer finds the end of an ordered list
     * It calls command for the end of an ordered list in latex, even with right indention
     */
    function listo_close() {
        $this->_list_close("enumerate");
    }

    /**
     * function is called, when renderer finds start of a list item
     * It calls command for a list item in latex, even with right indention
     * @param int $level Level of indention.
     */
    function listitem_open($level) {
        $this->last_level = $level;
        $this->_indent_list();
        $this->doc .= "  \\item";
    }

    /**
     * function is called, when renderer finds the end of a list item content
     * It adds newline to the latex file.
     */
    function listcontent_close() {
        $this->doc .= "\n";
    }

    /**
     * Original text is not formatted by DW, so this function just inserts the text as it is.
     * It just escapes special characters.
     * @param string $text Unformatted text.
     */
    function unformatted($text) {
        $this->doc .= $this->_latexSpecialChars($text);
    }

    /**
     * Inserts PHP code to the document.
     * @param string $text PHP code.
     */
    function php($text) {
        $this->code($text, "PHP");
    }

    /**
     * Inserts block of PHP code to the document.
     * @param string $text PHP code.
     */
    function phpblock($text) {
        $this->code($text, "PHP");
    }

    /**
     * Inserts HTML code to the document.
     * @param string $text HTML code.
     */
    function html($text) {
        $this->code($text, "HTML");
    }

    /**
     * Inserts block of HTML code to the document.
     * @param string $text HTML code.
     */
    function htmlblock($text) {
        $this->code($text, "HTML");
    }

    /**
     * Inserts preformatted text (with all whitespaces)
     * @param string $text Preformatted text.
     */
    function preformatted($text) {
        $this->doc .= "\n\begin{verbatim}\n";
        $this->doc .= $this->_latexSpecialChars($text);
        $this->doc .= "\n".'\end{verbatim}'."\n";
    }

    /**
     * Opens the quote environment.
     */
    function quote_open() {
        $this->doc .= "\n\begin{quote}\n";
    }

    /**
     * Closes the quote environment.
     */
    function quote_close() {
        $this->doc .= "\n".'\end{quote}'."\n";
    }

 
    /**
     * * File tag is almost the same like the code tag, but it enables to download
     * the code directly from DW. 
     * Therefore we just add the filename to the top of code.
     * @param string $text The code itself.
     * @param string $lang Programming language.
     * @param string $file The code will be exported from DW as a file.
     */
    function file($text, $lang = null, $file = null) {
        $this->code($text, $lang, $file);
    }

    /**
     * Function adds a block of programming language code to LaTeX file
     * using the listings package.
     * @param string $text The code itself.
     * @param string $lang Programming language.
     * @param string $file The code can be inserted to DokuWiki as a file.
     */
    function code($text, $lang = null, $file = null) {
        //FIXME konfigurace?
        $pckg = new Package('listings');
        $this->_addPackage($pckg);
        $this->_open('lstset');
        $this->doc .= 'frame=single';
        if (!is_null($lang)) {
            //if language name is specified, insert it to LaTeX
            $this->doc .= ', language=';
            $this->doc .= $this->_latexSpecialChars($lang);   
        }
         //insert filename
        if(!is_null($file)) {
           $this->doc .= ', title=';
           $this->doc .= $this->_latexSpecialChars($file);   
        }
        $this->_close();
        $this->doc .= "\n";
        //open the code block
        $this->_open('begin');
        $this->doc .= 'lstlisting';
        $this->_close();
        $this->doc .= "\n";
       
        //get rid of some non-standard characters
        $text = str_replace('”', '"', $text);
        $text = str_replace('–', '-', $text);
        $this->doc .= $text;
        //close the code block
        $this->_open('end');
        $this->doc .= 'lstlisting';
        $this->_close();
        $this->doc .= "\n\n";
    }

    
    /**
     * This function is called when an acronym is found. It just inserts it as a classic text.
     * I decided not to implement the mouse over text, although it is possible, but
     * it does not work in all PDF browsers. 
     * http://tex.stackexchange.com/questions/32314/is-there-an-easy-way-to-add-hover-text-to-all-incidents-of-math-mode-where-the-h
     * @param string $acronym The Acronym.
     */
    function acronym($acronym) {
        $this->doc .= $this->_latexSpecialChars($acronym);
    }

    /**
     * This function is called when a smiley is found.
     * LaTeX does not support smileys, so they are inserted as a normal text.
     * FIXME and DELETEME are exceptions, they are highlited (in the end of exporting).
     * @param string $smiley Smiley chars.
     */
    function smiley($smiley) {
        if ($smiley == 'FIXME' || $smiley == 'DELETEME') {
            $pckg = new Package('soul');
            $this->_addPackage($pckg);
            $this->doc .= $smiley;
        } else {
            $this->doc .= $this->_latexSpecialChars($smiley);
        }
    }

    /**
     * DocuWiki can represent some characters as they typograficaly correct entities.
     * Most of them exist in LaTeX as well, but some only in math mode.
     * @param string $entity An entity.
     */
    function entity($entity) {
        //this text is removed after exporting
        //it is here to disallow double escaping of some math characters
        $this->doc .= '///ENTITYSTART///';
        switch ($entity) {
            case '->':
                $this->doc .= '$\rightarrow$';
                break;
            case '<-':
                $this->doc .= '$\leftarrow$';
                break;
            case '<->':
                $this->doc .= '$\leftrightarrow$';
                break;
            case '=>':
                $this->doc .= '$\Rightarrow$';
                break;
            case '<=':
                $this->doc .= '$\Leftarrow$';
                break;
            case '<=>':
                $this->doc .= '$\Leftrightarrow$';
                break;
            case '(c)':
                $this->doc .= '\copyright ';
                break;
            case '(tm)':
                $this->doc .= '\texttrademark ';
                break;
            case '(r)':
                $this->doc .= '\textregistered ';
                break;
            default:
                $this->doc .= $this->_latexSpecialChars($entity);
                break;
        }
        $this->doc .= '///ENTITYEND///';
    }

   /**
    * Inserts multiply entity (eg. 640x480) to LaTeX file.
    * @param int $x First number
    * @param int $y Second number
    */
    function multiplyentity($x, $y) {
        $this->doc .= '///ENTITYSTART///';
        $this->doc .= '$';
        $this->doc .= $this->_latexSpecialChars($x);
        $this->doc .= ' \times ';
        $this->doc .= $this->_latexSpecialChars($y);
        $this->doc .= '$';
        $this->doc .= '///ENTITYEND///';
    }

    /**
     * Inserts single quote opening to LaTeX depending on set language.
     */
    function singlequoteopening() {
        //FIXME  jine jazyky viz ODT plugin
        $this->doc .= '`';
    }

    /**
     * Inserts single quote closing to LaTeX depending on set language.
     */
    function singlequoteclosing() {
        //FIXME  jine jazyky viz ODT plugin
        $this->doc .= '\'';
    }

    /**
     * Inserts apostrophe to LaTeX depending on set language.
     */
    function apostrophe() {
        //FIXME  jine jazyky viz ODT plugin
        $this->doc .= '\'';
    }

    /**
     * Inserts double quote opening to LaTeX depending on set language.
     */
    function doublequoteopening() {
        //FIXME  jine jazyky viz ODT plugin
        $this->doc .= ',,';
        //$this->doc .= '\\uv{';
        //english ``
    }

    /**
     * Inserts double quote closing to LaTeX depending on set language.
     */
    function doublequoteclosing() {
        //FIXME  jine jazyky
        $this->doc .= '"';
        //$this->doc .= '}';
        //english "
    }

    /**
     * Function is called, when renderer finds a link written in text like CamelCase.
     * It just calls the common link function.
     * @param string $link Internal link to a wiki page.
     */
    function camelcaselink($link) {
        $this->internallink($link, $link);
    }

    /**
     * This function handles the links on the page itself (#something at the end of URL)
     * It inserts reference to LaTeX document
     * @param string $hash Label of a section
     * @param string $name Text of the original link
     */
    function locallink($hash, $name = NULL) {
        $this->_insertLinkPackages();
        if(!is_null($name)) {
            $this->doc .= $this->_latexSpecialChars($name);
        } else {
            $this->doc .= $this->_latexSpecialChars($hash);
        }
        $this->doc .= ' (\autoref{'.$hash.'})';
    }

    /**
     * function is called, when renderer finds an internal link
     * It resolves the internal link (namespaces, URL)
     * Depending on the configuration:
     *     It handles link as an external and calls proper function in LaTeX depending on the title
     *     It recursively adds the linked page to the exported LaTeX file
     * This feature is not in classic plugin configuration.
     * If you want to have a link recursively inserted, add ~~RECURSIVE~~ just before it.
     * The count of ~ means the same as = for headers. It will determine the 
     * level of first header used in recursively inserted text.
     * @param string $link Internal link (can be without proper namespace)
     * @param string/array $title Title, can be null or array (if it is media)
     */
    function internallink($link, $title = NULL) {
        //register globals
        global $ID; //in this global var DokuWiki stores the current page id with namespaces
        global $latexit_level;
        global $latexit_headers;

        //escape link title
        $title = $this->_latexSpecialChars($title);

        $link_original = $link;

        //get current namespace from current page
        $current_namespace = getNS($ID);
        //get the page ID with right namespaces
        //$exists stores information, if the page exists. We don't care about that right now. FIXME?
        resolve_pageid($current_namespace, $link, $exists);

        $params = '';
        $absoluteURL = true;
        //get the whole URL
        $url = wl($link, $params, $absoluteURL);
        $url = $this->_secureLink($url);
        //FIXME keep hash in the end? have to test!
        //FIXME s hashem na konci by se dalo odkazovat na jednotlive sekce dokumentu
        //teoreticky by se tak dal resit i potencialni rekurze
        //FIXME configurable
        if ($this->recursive) {
            //FIXME bacha na nekonecnou rekurzi
            $latexit_level = $this->recursion_level + 1;
            $latexit_headers = $this->headers_level;

            //start parsing linked page
            $data = p_cached_output(wikifn($link), 'latexit');
            $data = $this->_loadPackages($data);
            $this->doc .= "\n\n";
            //insert comment to LaTeX
            $this->doc .= "%RECURSIVELY INSERTED FILE START";
            $this->doc .= "\n\n";
            $this->doc .= $data;
            $this->doc .= "\n\n";
            //insert comment to LaTeX
            $this->doc .= "%RECURSIVELY INSERTED FILE END";
            $this->doc .= "\n\n";
            //get headers level to previous level
            $this->headers_level -= $this->last_level_increase;
        } else {
            //FIXME refactor to one function?
            //handle internal links as they were external
            $this->_insertLinkPackages();
            //FIXME title pictures
            if (is_null($title) || trim($title) == '') {
                $this->doc .= '\\href{' . $url . '}{' . $link_original . '}';
            } else {
                $this->doc .= '\\href{' . $url . '}{' . $title . '}';
            }
        }
        $this->recursive = FALSE;
    }

    /**
     * function is called, when renderer finds an external link
     * It calls proper function in LaTeX depending on the title
     * @param string $link External link
     * @param string/array $title Title, can be null or array (if it is media)
     */
    function externallink($link, $title = NULL) {
        $title = $this->_latexSpecialChars($title);
        $link = $this->_secureLink($link);
        $this->_insertLinkPackages();
        //FIXME pictures
        if (is_null($title) || trim($title) == '') {
            $this->doc .= '\\url{' . $link . '}';
        } else {
            $this->doc .= '\\href{' . $link . '}{' . $title . '}';
        }
    }

    //FIXME
    function rss($url, $params) {
        
    }

   /**
    * InterWiki links lead to another wikis and they can be written in special syntax.
    * This resolves the link and inserts it as normal external link.
    * @param string $link Original link in DW syntax
    * @param string $title Title of link, can also be image
    * @param string $wikiName Name of wiki (according to configuration)
    * @param string $wikiUri Text in link after wiki address
    */
    function interwikilink($link, $title = NULL, $wikiName, $wikiUri) {
        $url = $this-> _resolveInterWiki($wikiName,$wikiUri);
        if (is_null($title)) {
            $name = $wikiUri;
        } else {
            $name = $title;
        }
        $this->externallink($url, $name);
    }

    /**
     * Inserts a link to a file on local filesystem.
     * It just handles the link as an external link.
     * @param string $link Link to a file.
     * @param string $title Title of the link, can be image.
     */
    function filelink($link, $title = NULL) {
        $this->externallink($link, $title);
    }

    /**
     * Inserts a link to a Windows share intranet server.
     * It just handles the link as an external link.
     * @param string $link Link to a file.
     * @param string $title Title of the link, can be image.
     */
    function windowssharelink($link, $title = NULL) {
        $this->externallink($link, $title);
    }

    /**
     * function is called, when renderer finds an email link
     * It calls proper function in LaTeX depending on the name and sets mailto
     * @param string $address Email address
     * @param string/array $name Name, can be null or array (if it is media)
     */
    function emaillink($address, $name = NULL) {
        $name = $this->_latexSpecialChars($name);
        $this->_insertLinkPackages();
        //FIXME pictures
        if (is_null($name) || trim($name) == '') {
            $this->doc .= '\\href{mailto:' . $address . '}{' . $address . '}';
        } else {
            $this->doc .= '\\href{mailto:' . $address . '}{' . $name . '}';
        }
    }

    //FIXME
    function internalmedia($src, $title = NULL, $align = NULL, $width = NULL, $height = NULL, $cache = NULL, $linking = NULL) {
        $pckg = new Package('graphicx');
        $pckg->addCommand('\\graphicspath{{images/}}');
        $this->_addPackage($pckg);
        $namespaces = explode(':', $src);
        for ($i = 1; $i < count($namespaces); $i++) {
            if ($i != 1) {
                $path .= "/";
            }
            $path .= $namespaces[$i];
        }
        //http://stackoverflow.com/questions/2395882/how-to-remove-extension-from-string-only-real-extension
        $path = preg_replace("/\\.[^.\\s]{3,4}$/", "", $path);
        $this->doc .= "\includegraphics{" . $path . "}";
    }

    //FIXME
    function externalmedia($src, $title = NULL, $align = NULL, $width = NULL, $height = NULL, $cache = NULL, $linking = NULL) {
        
    }

    //FIXME
    function internalmedialink(
    $src, $title = NULL, $align = NULL, $width = NULL, $height = NULL, $cache = NULL
    ) {
        var_dump($src);
    }

    //FIXME
    function externalmedialink(
    $src, $title = NULL, $align = NULL, $width = NULL, $height = NULL, $cache = NULL
    ) {
        
    }

    /**
     * Function is called, when a renderer finds a start of an table.
     * It inserts needed packages and the header of the table.
     * @param int $maxcols Maximum of collumns in the table
     * @param int $numrows Number of rows in table (not required in LaTeX)
     * @param int $pos This parameter is not required by LaTeX.
     */
    function table_open($maxcols = null, $numrows = null, $pos = null) {
        $this->table_cols = $maxcols;
        //set environment to tables
        $this->in_table = true;
        $pckg = new Package('longtable');
        $this->_addPackage($pckg);
        //print the header
        $this->doc .= "\\begin{longtable}{|";
        for ($i = 0; $i < $maxcols; $i++) {
            $this->doc .= $this->default_table_align . "|";
        }
        $this->doc .= "}\n\hline\n";
    }

    /**
     * Function is called in the end of every table.
     * It prints the footer of the table.
     * @param int $pos Not required in LaTeX.
     */
    function table_close($pos = null) {
        //close the table environment
        $this->in_table = false;
        //print the footer
        $this->doc .= "\\end{longtable}\n\n";
    }

    /**
     * Function is called at start of every row in a table.
     */
    function tablerow_open() {
        //set the number of cells printed
        $this->cells_count = 0;
    }

    /**
     * Function is called at the end of every row in a table
     */
    function tablerow_close() {
        //add syntax for end of a row
        $this->doc .= " \\\\ \n";
        //add line
        $this->doc .= "\\hline \n";
    }

    /**
     * Function is called when the header row is reached.
     * It just prints regular row in bold.
     * @param type $colspan
     * @param type $align
     * @param type $rowspan
     */
    function tableheader_open($colspan = 1, $align = NULL, $rowspan = 1) {
        $this->tablecell_open($colspan, $align, $rowspan);
        $this->_open('textbf');

        /* FIXME poresit zobrazovani techto nasledujicich prikazu
         * \endfirsthead: Line(s) to appear as head of the table on the first page
          \endhead: Line(s) to appear at top of every page (except first)
          \endfoot: Last line(s) to appear at the bottom of every page (except last)
          \endlastfoot: Last line(s) to appear at the end of the table
         */
    }

    /**
     * Function is called at the end of the header row.
     */
    function tableheader_close() {
        $this->_close();
        $this->tablecell_close();
    }

    /**
     * Function handling exporting of each cell in a table.
     * @param int $colspan Sets collspan of the cell.
     * @param string $align Sets align of the cell. 
     * @param int $rowspan Sets rows[am of the cell.
     */
    function tablecell_open($colspan = 1, $align = NULL, $rowspan = 1) {
        if ($align == NULL) {
            $align = $this->default_table_align;
        } else {
            //in DW align is left, right, center, in LaTeX just first letter
            $align = substr($align, 0, 1);
        }
        //if anything is not standard, we will have to use different closing of a cell
        $this->last_colspan = $colspan;
        $this->last_rowspan = $rowspan;
        $this->last_align = $align;

        //RowspanHandler stores information about the number of cells to be rowspanned
        if ($this->rowspan_handler->getRowspan($this->cells_count) != 0) {
            $this->doc .= ' & ';
            $this->rowspan_handler->decreaseRowspan($this->cells_count);
            $this->cells_count++;
        }

        //colspan or not default align
        if ($colspan != 1 || $align != $this->default_table_align) {
            $this->doc .= "\\multicolumn{" . $colspan . "}{|$align|}{";
        }
        //start a new rowspan using RowspanHandler
        if ($rowspan != 1) {
            $pckg = new Package('multirow');
            $this->_addPackage($pckg);
            $this->rowspan_handler->insertRowspan($rowspan - 1, $this->cells_count);
            $this->doc .= "\\multirow{" . $rowspan . "}{*}{";
        }
    }

    /**
     * Function is called at the end of every cell.
     */
    function tablecell_close() {
        //colspan or align different from default has been set in this cell
        if ($this->last_colspan != 1 || $this->last_align != $this->default_table_align) {
            $this->doc .= "}";
        }
        //rowspan has been set in this cell
        if ($this->last_rowspan != 1) {
            $this->doc .= "}";
        }

        //are there any cells left in this row?
        $this->cells_count += $this->last_colspan;
        if ($this->table_cols != $this->cells_count) {
            $this->doc .= " & ";
        }
    }

    /**
     * Syntax of almost every LaTeX command is alway the same.
     * @param $command The name of a LaTeX command.
     */
    private function _open($command) {
        $this->doc .= "\\" . $command . "{";
    }

    /**
     * Closing tag of all LaTeX commands is always same and will be called
     * in almost every close function.
     */
    private function _close() {
        $this->doc .= '}';
    }

    /**
     * Adds name of new package to packages array, but prevents duplicates
     * @param $package LaTeX package to be used in rendering.
     */
    private function _addPackage($package) {
        foreach ($this->packages as $p) {
            if ($p->getName() == $package->getName()) {
                return;
            }
        }
        $this->packages[] = $package;
    }

    /**
     * Inserts all packages collected during the rendering to the head of the document.
     */
    private function _insertPackages() {
        //if the page is recursively inserted, packages will have to be added to the parent document
        //they are serilized in the exported subfile
        if ($this->_immersed()) {
            $packages = serialize($this->packages);
        } else {
            //FIXME slucovat balicky bez parametru - nejdriv ty s parametry, pak ty bez nich
            foreach ($this->packages as $package) {
                $param = $this->_latexSpecialChars($package->printParameters());
                $packages .= "\\usepackage$param{" . $this->_latexSpecialChars($package->getName()) . "}\n";
                $packages .= $package->printCommands();
            }
        }
        //put the packages text to an appropriate place
        $this->doc = str_replace('~~~PACKAGES~~~', $packages, $this->doc);
    }

    /**
     * Function used for inserting packages from recursively inserted pages to the main page
     * It loads packages from given data and adds them as a packages.
     * @param string $data Parsed subpage.
     * @return string Parsed subpage without packages data (and with packages loaded)
     */
    private function _loadPackages($data) {
        preg_match('#~~~PACKAGES-START~~~(.*?)~~~PACKAGES-END~~~#si', $data, $pckg);
        $data = preg_replace('#~~~PACKAGES-START~~~.*~~~PACKAGES-END~~~#si', '', $data);

        //load packages and insert them
        $packages = unserialize($pckg[1]);
        if (!is_null($packages) && is_array($packages)) {
            foreach ($packages as $package) {
                $this->_addPackage($package);
            }
        }
        return $data;
    }

    /**
     * Function inserts package used for hyperlinks.
     */
    private function _insertLinkPackages() {
        $package = new Package('hyperref');
        //fixes the encoding warning
        $package->addParameter('unicode');
        $this->_addPackage($package);
    }

    /**
     * Function used for exporting lists, they differ only by command.
     * @param string $command Proper LaTeX list command
     */
    private function _list_open($command) {
        $this->doc .= "\n";
        if ($this->list_opened) {
            for ($i = 1; $i < $this->last_level + 1; $i++) {
                $this->doc .= '  ';
            }
        } else {
            $this->list_opened = TRUE;
        }
        $this->_indent_list();
        $this->doc .= "\\begin{" . $command . "}\n";
    }

    /**
     * Function used for exporting the end of lists, they differ only by command.
     * @param string $command Proper LaTeX list command
     */
    private function _list_close($command) {
        if ($this->last_level == 1) {
            $this->list_opened = FALSE;
        }
        $this->_indent_list();
        $this->doc .= "\\end{" . $command . "}\n";
    }

    /**
     * This function highlights fix me DW command.
     * This format is used in some DokuWiki instances.
     * format is: FIXME[author](description of a thing to fix)
     * (this feature comes from CCM at FIT CVUT, for whom I write the plugin)
     */
    private function _highlightFixme() {
        $this->doc = str_replace('FIXME', '\hl{FIXME}', $this->doc);
        $this->doc = str_replace('DELETEME', '\hl{DELETEME}', $this->doc);
        $this->doc = preg_replace_callback('#{FIXME}\[(.*?)\]\((.*?)\)#si', array(&$this, '_highlightFixmeHandler'), $this->doc);
    }

    /**
     * Function handling parsing of the fix me DW command.
     * @param array of strings $matches strings from the regex
     * @return regex result replacement
     */
    private function _highlightFixmeHandler($matches) {
        $matches[1] = $this->_stripDiacritics($matches[1]);
        $matches[2] = $this->_stripDiacritics($matches[2]);
        return '{FIXME[' . $matches[1] . '](' . $matches[2] . ')}';
    }

    /**
     * Indents the list according to the last seen level.
     */
    private function _indent_list() {
        for ($i = 1; $i < $this->last_level; $i++) {
            $this->doc .= '  ';
        }
    }

    /**
     * Insert header to the LaTeX document with right level command.
     * @param string $level LaTeX command for header on right level.
     * @param string $text Text of the Header.
     */
    private function _header($level, $text) {
        $this->_open($level);
        $this->doc .= $this->_latexSpecialChars($text);
        $this->_close();
        $this->doc .= "\n";
    }

    /**
     * This function finds out, if the current renderer is immersed in recursion.
     * @return boolean Is immersed in recursion?
     */
    private function _immersed() {
        if ($this->recursion_level > 0) {
            return true;
        }
        return false;
    }

    /**
     * Escapes LaTeX special chars.
     * Entities are in the middle of special tags so eg. MathJax texts are not escaped, but entities are.
     * @param string $text Text to be escaped.
     * @return string Escaped text.
     */
    private function _latexSpecialChars($text) {
        //find only entities in TEXT, not in eg MathJax
        preg_match('#///ENTITYSTART///(.*?)///ENTITYEND///#si', $text, $entity);
        //replace classic LaTeX escape chars
        $text = str_replace(array('\\', '{', '}', '&', '%', '$', '#', '_', '~', '^', '<', '>'), array('\textbackslash', '\{', '\}', '\&', '\%', '\$', '\#', '\_', '\textasciitilde{}', '\textasciicircum{}', '\textless ', '\textgreater '), $text);
        //finalize escaping
        $text = str_replace('\\textbackslash', '\textbackslash{}', $text);
        //replace entities in TEXT
        $text = preg_replace('#///ENTITYSTART///(.*?)///ENTITYEND///#si', $entity[1], $text);
        return $text;
    }

    /**
     * Function replaces entities, which have not been replaced using _latexSpecialChars function
     */
    private function _removeEntities() {
        $this->doc = preg_replace('#///ENTITYSTART///(.*?)///ENTITYEND///#si', '$1', $this->doc);

        //FIXME - this has to be changed in imagereference plugin - just a walkaround
        //respective musim implementovat kompletni walkaround pro imagereference :)
        $this->doc = str_replace('[h!]{\centering}', '[!ht]{\centering}', $this->doc);
        $this->doc = str_replace('\\ref{', '\autoref{', $this->doc);
    }

    /**
     * Function sets, if the next link will be inserted to the file recursively.
     * @param bool $recursive Will next link be added recursively?
     */
    public function _setRecursive($recursive) {
        $this->recursive = $recursive;
    }

    /**
     * Function increases header level of a given number.
     * @param int $level Size of the increase.
     */
    public function _increaseLevel($level) {
        $this->last_level_increase = $level;
        $this->headers_level += $level;
    }

    /**
     * function replacing some characters in MathJax mode
     * @param string $data Parsed text.
     */
    public function _mathMode($data) {
        //FIXME toto je ale proti zasadam latexu
        $data = str_replace('->', '\rightarrow', $data);
        $data = str_replace('<-', '\leftarrow', $data);
        $data = str_replace('<->', '\leftrightarrow', $data);
        $data = str_replace('=>', '\Rightarrow', $data);
        $data = str_replace('<=', '\Leftarrow', $data);
        $data = str_replace('<=>', '\Leftrightarrow', $data);
        $data = str_replace('...', '\ldots', $data);
        $data = str_replace('−', '-', $data);

        $this->doc .= $data;
    }
    
    /**
     * Function creates label from a header name.
     * @param string $text A header name.
     * @return string Label
     */
    private function _createLabel($text) {
        $text = strtolower($text);
        $text = str_replace(" ", "_", $text);
        return $text;
   }

   /**
    * Escapes backslash in the URL.
    * @param string $link The URL.
    * @return string Escaped URL.
    */
   private function _secureLink($link) {
       return str_replace("\\", "\\\\", $link);
   }
   
    /**
     * Function removing diacritcs from a text.
     * @param string $data Text with diacritics
     * @return string Text withou diacritics
     */
    private function _stripDiacritics($data) {
        $table = Array(
            'ä' => 'a',
            'Ä' => 'A',
            'á' => 'a',
            'Á' => 'A',
            'à' => 'a',
            'À' => 'A',
            'ã' => 'a',
            'Ã' => 'A',
            'â' => 'a',
            'Â' => 'A',
            'č' => 'c',
            'Č' => 'C',
            'ć' => 'c',
            'Ć' => 'C',
            'ď' => 'd',
            'Ď' => 'D',
            'ě' => 'e',
            'Ě' => 'E',
            'é' => 'e',
            'É' => 'E',
            'ë' => 'e',
            'Ë' => 'E',
            'è' => 'e',
            'È' => 'E',
            'ê' => 'e',
            'Ê' => 'E',
            'í' => 'i',
            'Í' => 'I',
            'ï' => 'i',
            'Ï' => 'I',
            'ì' => 'i',
            'Ì' => 'I',
            'î' => 'i',
            'Î' => 'I',
            'ľ' => 'l',
            'Ľ' => 'L',
            'ĺ' => 'l',
            'Ĺ' => 'L',
            'ń' => 'n',
            'Ń' => 'N',
            'ň' => 'n',
            'Ň' => 'N',
            'ñ' => 'n',
            'Ñ' => 'N',
            'ó' => 'o',
            'Ó' => 'O',
            'ö' => 'o',
            'Ö' => 'O',
            'ô' => 'o',
            'Ô' => 'O',
            'ò' => 'o',
            'Ò' => 'O',
            'õ' => 'o',
            'Õ' => 'O',
            'ő' => 'o',
            'Ő' => 'O',
            'ř' => 'r',
            'Ř' => 'R',
            'ŕ' => 'r',
            'Ŕ' => 'R',
            'š' => 's',
            'Š' => 'S',
            'ś' => 's',
            'Ś' => 'S',
            'ť' => 't',
            'Ť' => 'T',
            'ú' => 'u',
            'Ú' => 'U',
            'ů' => 'u',
            'Ů' => 'U',
            'ü' => 'u',
            'Ü' => 'U',
            'ù' => 'u',
            'Ù' => 'U',
            'ũ' => 'u',
            'Ũ' => 'U',
            'û' => 'u',
            'Û' => 'U',
            'ý' => 'y',
            'Ý' => 'Y',
            'ž' => 'z',
            'Ž' => 'Z',
            'ź' => 'z',
            'Ź' => 'Z'
        );

        return strtr($data, $table);
    }

}
