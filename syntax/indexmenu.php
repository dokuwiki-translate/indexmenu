<?php
/**
 * Info Indexmenu: Show a customizable and sortable index for a namespace.
 *
 * @license     GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author      Samuele Tognini <samuele@samuele.netsons.org>
 *
 */

use dokuwiki\plugin\indexmenu\Search;

if(!defined('INDEXMENU_IMG_ABSDIR')) define('INDEXMENU_IMG_ABSDIR', DOKU_PLUGIN."indexmenu/images");

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_indexmenu_indexmenu extends DokuWiki_Syntax_Plugin {

    /**
     * What kind of syntax are we?
     */
    public function getType() {
        return 'substition';
    }

    /**
     * Behavior regarding the paragraph
     */
    public function getPType() {
        return 'block';
    }

    /**
     * Where to sort in?
     */
    public function getSort() {
        return 138;
    }

    /**
     * Connect pattern to lexer
     *
     * @param string $mode
     */
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('{{indexmenu>.+?}}', $mode, 'plugin_indexmenu_indexmenu');
    }

    /**
     * Handler to prepare matched data for the rendering process
     *
     * @param   string       $match   The text matched by the patterns
     * @param   int          $state   The lexer state for the match
     * @param   int          $pos     The character position of the matched text
     * @param   Doku_Handler $handler The Doku_Handler object
     * @return  array Return an array with all data you want to use in render
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        $theme    = 'default'; //name of theme for images and additional css
        $level    = -1; // requested depth of initial opened nodes, -1:all
        $max      = 0; // number of levels loaded initially, rest should be loaded with ajax. (TODO actual default is 1)
        $maxAjax  = 1; // number of levels loaded per ajax request
        $subNSs   = [];
        $skipNs   = [];
        $skipFile = [];
        /* @deprecated 2022-04-15 dTree only */
        $maxJs    = 1;
        /* @deprecated 2022-04-15 dTree only */
        $gen_id   = 'random';
        /* @deprecated 2021-07-01 -- allow (temporary) switching between versions of the js treemenu */
        $jsVersion = 1; // 0:both, 1:dTree, 2:Fancytree
        /* @deprecated 2022-04-15 dTree only */
        $jsAjax   = '';

        $defaultsStr = $this->getConf('defaultoptions');
        $defaults = explode(' ', $defaultsStr);

        $match = substr($match, 12, -2);
        //split namespace,level,theme
        list($nsStr, $optsStr) = explode('|', $match, 2);
        //split options
        $opts = explode(' ', $optsStr);

        //Context option
        $context = $this->hasOption($defaults, $opts, 'context');

        //split subnamespaces with their level of open/closed nodes
        $nsStrs = preg_split("/ /u", $nsStr, -1, PREG_SPLIT_NO_EMPTY); // no empty filters multiple spaces
        //skips i=0 because that becomes main $ns
        for($i = 1; $i < count($nsStrs); $i++) {
            $subns_lvl = explode("#", $nsStrs[$i]);
            //context should parse this later in correct context
            if(!$context) {
                $subns_lvl[0] = $this->parseNs($subns_lvl[0]);
            }
            $subNSs[] = [
                $subns_lvl[0], //subns
                is_numeric($subns_lvl[1]) ? $subns_lvl[1] : -1 // level
            ];
        }

        //split main requested namespace
        if(preg_match('/(.*)#(\S*)/u', $nsStrs[0], $matched_ns_lvl)) {
            //split level
            $ns = $matched_ns_lvl[1];
            if(is_numeric($matched_ns_lvl[2])) {
                $level = $matched_ns_lvl[2];
            }
        } else {
            $ns = $nsStrs[0];
        }
        if(!$context) {
            $ns = $this->parseNs($ns);
        } elseif($ns === null) {
            $ns = '';
        }

        //nocookie option (disable for uncached pages)
        $nocookie = $context || $this->hasOption($defaults, $opts, 'nocookie');
        //noscroll option
        $noscroll = $this->hasOption($defaults, $opts, 'noscroll');
        //Open at current namespace option
        $navbar = $this->hasOption($defaults, $opts, 'navbar');
        //no namespaces  options
        $nons = $this->hasOption($defaults, $opts, 'nons');
        //no pages option
        $nopg = $this->hasOption($defaults, $opts, 'nopg');
        //disable toc preview
        $notoc = $this->hasOption($defaults, $opts, 'notoc');
        //disable the right context menu
        $nomenu = $this->hasOption($defaults, $opts, 'nomenu');
        //Main sort method
        $tsort = $this->hasOption($defaults, $opts, 'tsort');
        $dsort = $this->hasOption($defaults, $opts, 'dsort');
        if($tsort) {
            $sort = 't';
        } elseif($dsort) {
            $sort = 'd';
        } else {
            $sort = 0;
        }
        //sort directories in the same way as files
        $nsort = $this->hasOption($defaults, $opts, 'nsort');
        //sort headpages up
        $hsort = $this->hasOption($defaults, $opts, 'hsort');
        //Metadata sort method
        if($msort = $this->hasOption($defaults, $opts, 'msort')) {
            $msort = 'indexmenu_n';
        } elseif($value = $this->getOption($defaultsStr, $optsStr, '/msort#(\S+)/u')) {
            $msort = str_replace(':', ' ', $value);
        }
        //reverse sort
        $rsort = $this->hasOption($defaults, $opts, 'rsort');

        if($sort) $jsAjax .= "&sort=" . $sort;
        if($msort) $jsAjax .= "&msort=" . $msort;
        if($rsort) $jsAjax .= "&rsort=1";
        if($nsort) $jsAjax .= "&nsort=1";
        if($hsort) $jsAjax .= "&hsort=1";
        if($nopg) $jsAjax .= "&nopg=1";

        //javascript option
        $dir = '';
        //check defaults for js,js#theme, #theme
        if(!$js = in_array('js', $defaults)) {
            if(preg_match('/(?:^|\s)(js)?#(\S*)/u', $defaultsStr, $matched_js_theme) > 0) {
                if(!empty($matched_js_theme[1])) {
                    $js = true;
                }
                if(isset($matched_js_theme[2])) {
                    $dir = $matched_js_theme[2];
                }
            }
        }
        //check opts for nojs,#theme or js,js#theme
        if($js) {
            if(in_array('nojs', $opts)) {
                $js = false;
            } else {
                if(preg_match('/(?:^|\s)(?:js)?#(\S*)/u', $optsStr, $matched_theme) > 0) {
                    if(isset($matched_theme[1])) {
                        $dir = $matched_theme[1];
                    }
                }
            }
        } else {
            if($js = in_array('js', $opts)) {
                //use theme from the defaults
            } else {
                if(preg_match('/(?:^|\s)js#(\S*)/u', $optsStr, $matched_theme) > 0) {
                    $js = true;
                    if(isset($matched_theme[1])) {
                        $dir = $matched_theme[1];
                    }
                }
            }
        }

        if($js) {
            //exist theme?
            if(!empty($dir) && is_dir(INDEXMENU_IMG_ABSDIR . "/" . $dir)) {
                $theme = $dir;
            }

            //id generation method
            $gen_id = $this->getOption($defaultsStr, $optsStr, '/id#(\S+)/u');

            //max option: #n is no of lvls during initialization , #m levels retrieved per ajax request
            if($matched_lvl_sublvl = $this->getOption($defaultsStr, $optsStr, '/max#(\d+)($|\s+|#(\d+))/u', true)) {
                $max = $matched_lvl_sublvl[1];
                if($matched_lvl_sublvl[3]) {
                    $jsAjax .= "&max=" . $matched_lvl_sublvl[3];
                    $maxAjax = (int) $matched_lvl_sublvl[3];
                }
                //disable cookie to avoid javascript errors
                $nocookie = true;
            } else {
                $max = 0;
            }
//error_log(print_r($matched_lvl_sublvl,true));
//            error_log('max:'.$max);
            //max js option
            if($maxjs_lvl = $this->getOption($defaultsStr, $optsStr, '/maxjs#(\d+)/u')) {
                $maxJs = $maxjs_lvl;
            }
            /* @deprecated 2021-07-01 -- allow (temporary) switching between versions of the js treemenu */
            $treeNew = $this->hasOption($defaults, $opts, 'treenew'); //overrides old and both
            /* @deprecated 2021-07-01 -- allow (temporary) switching between versions of the js treemenu */
            $treeOld = $this->hasOption($defaults, $opts, 'treeold'); //overrides both
            /* @deprecated 2021-07-01 -- allow (temporary) switching between versions of the js treemenu */
            $treeBoth = $this->hasOption($defaults, $opts, 'treeboth');
//            $jsVersion = $treeNew ? 2 : ($treeOld ? 1 : ($treeBoth ? 0 : $jsVersion));
            $jsVersion = $treeOld ? 1 : ($treeNew ? 2 : ($treeBoth ? 0 : $jsVersion));
            error_log('$treeOld:'.$treeOld.'$treeNew:'.$treeNew.'$treeBoth:'.$treeBoth);
        }
        if(is_numeric($gen_id)) {
            $identifier = $gen_id;
        } elseif($gen_id == 'ns') {
            $identifier = sprintf("%u", crc32($ns));
        } else {
            $identifier = uniqid(rand());
        }

        //skip namespaces in index
        $skipNs[] = $this->getConf('skip_index');
        if(preg_match('/skipns[\+=](\S+)/u', $optsStr, $matched_skipns) > 0) {
            //first sign is: '+' (parallel to conf) or '=' (replace conf)
            $action = $matched_skipns[0][6];
            $index = 0;
            if($action == '+') {
                $index = 1;
            }
            $skipNs[$index] = $matched_skipns[1];
            $jsAjax .= "&skipns=" . utf8_encodeFN(($action == '+' ? '+' : '=') . $matched_skipns[1]);
        }
        //skip file
        $skipFile[] = $this->getConf('skip_file');
        if(preg_match('/skipfile[\+=](\S+)/u', $optsStr, $matched_skipfile) > 0) {
            //first sign is: '+' (parallel to conf) or '=' (replace conf)
            $action = $matched_skipfile[0][8];
            $index = 0;
            if($action == '+') {
                $index = 1;
            }
            $skipFile[$index] = $matched_skipfile[1];
            $jsAjax .= "&skipfile=" . utf8_encodeFN(($action == '+' ? '+' : '=') . $matched_skipfile[1]);
        }

        //js options
        $js_dTreeOpts = compact('theme', 'identifier', 'nocookie', 'navbar', 'noscroll', 'maxJs', 'notoc', 'jsAjax', 'context', 'nomenu');

        return [
            $ns, //0
            $js_dTreeOpts, //1=js_dTreeOpts
            [ //2=sort
                'sort' =>  $sort,
                'msort' =>  $msort,
                'rsort' =>  $rsort,
                'nsort' =>  $nsort,
                'hsort' => $hsort,
            ],
            [ //3=opts
                'level'         => $level, // requested depth of initial opened nodes, -1:all
                'nons'          => $nons,
                'nopg'          => $nopg,
                'subnss'        => $subNSs,
                'navbar'        => $navbar, //add current ns to subNSs
                'max'           => $max, //number of levels loaded initially, rest should be loaded with ajax
                'maxajax'       => $maxAjax, //number of levels loaded per ajax request
                'js'            => $js,
                'skipns'        => $skipNs,
                'skipfile'      => $skipFile,
                'headpage'      => $this->getConf('headpage'),
                'hide_headpage' => $this->getConf('hide_headpage'),
                'theme'         => $theme
            ],
            $jsVersion //4
        ];
    }

    /**
     * Looks if the default options and syntax options has the requested option
     *
     * @param array  $defaultsOpts array of default options
     * @param array  $opts         array of options provided via syntax
     * @param string $optionName   name of requested option
     * @return bool has $optionName?
     */
    private function hasOption($defaultsOpts, $opts, $optionName) {
        $name = $optionName;
        if(substr($optionName, 0, 2) == 'no') {
            $inverseName = substr($optionName, 2);
        } else {
            $inverseName = 'no' . $optionName;
        }

        if(in_array($name, $defaultsOpts)) {
            return !in_array($inverseName, $opts);
        } else {
            return in_array($name, $opts);
        }
    }

    /**
     * Looks for the value of the requested option in the default options and syntax options
     *
     * @param string $defaultsString     default options string
     * @param string $optsString         syntax options string
     * @param string $matchPattern    pattern to search for
     * @param bool   $multipleMatches if multiple returns array, otherwise the first match
     * @return string|array
     */
    private function getOption($defaultsString, $optsString, $matchPattern, $multipleMatches = false) {
        if(preg_match($matchPattern, $optsString, $match_o) > 0) {
            if($multipleMatches) {
                return $match_o;
            } else {
                return $match_o[1];
            }
        } elseif(preg_match($matchPattern, $defaultsString, $match_d) > 0) {
            if($multipleMatches) {
                return $match_d;
            } else {
                return $match_d[1];
            }
        }
        return false;
    }

    /**
     * Handles the actual output creation.
     *
     * @param string $format output format being rendered
     * @param Doku_Renderer $renderer the current renderer object
     * @param array $data data created by handler()
     * @return boolean rendered correctly?
     */
    public function render($format, Doku_Renderer $renderer, $data) {
        global $ACT;
        global $conf;
        global $INFO;

        $ns          = $data[0];
        $js_dTreeOpts = $data[1]; //theme, identifier, nocookie, navbar, noscroll, maxjs, notoc, jsajax, context, nomenu
        $sort        = $data[2]; //sort, msort, rsort, nsort, hsort
        $opts        = $data[3]; //opts for search(): level, nons, nopg, subnss, max, maxajax, js, skipns, skipfile, headpage, hide_headpage
        $jsVersion   = $data[4]; /* @deprecated 2021-07-01 temporary */

        if($format == 'xhtml') {
             /** @var Doku_Renderer_xhtml $renderer */
            if($ACT == 'preview') {
                //Check user permission to display indexmenu in a preview page
                if($this->getConf('only_admins') &&
                    $conf['useacl'] &&
                    $INFO['perm'] < AUTH_ADMIN
                ) {
                    return false;
                }
                //disable cookies
                $js_dTreeOpts['nocookie'] = true;
            }

            //Navbar with nojs
            if($js_dTreeOpts['navbar'] && !$opts['js']) {
                if(!isset($ns)) {
                    $ns = '..';
                }
                //add ns of current page to let open these nodes (within the $ns)
                $opts['subnss'][] = [getNS($INFO['id'])];
                $renderer->info['cache'] = false;
            }

            if($js_dTreeOpts['context']) {
                //resolve ns and subns's relative to current wiki page (instead of sidebar)
                $ns = $this->parseNs($ns, $INFO['id']);
                foreach($opts['subnss'] as $key=> $value) {
                    $opts['subnss'][$key][0] = $this->parseNs($value[0], $INFO['id']);
                }
                $renderer->info['cache'] = false;
            }

            //build index
            $n = $this->buildHtmlIndexmenu($ns, $js_dTreeOpts, $sort, $opts, $jsVersion);

            //alternative if empty
            if(!@$n) {
                $n = $this->getConf('empty_msg');
                $n = str_replace('{{ns}}', cleanID($ns), $n);
                $n = p_render('xhtml', p_get_instructions($n), $info);
            }
            $renderer->doc .= $n;
            return true;

        } else if($format == 'metadata') {
            /** @var Doku_Renderer_metadata $renderer */
            if(!($js_dTreeOpts['navbar'] && !$opts['js']) && !$js_dTreeOpts['context']) {
                //this is an indexmenu page that needs the PARSER_CACHE_USE event trigger;
                $renderer->meta['indexmenu'] = true;
            }
            $renderer->doc .= ((empty($ns)) ? $conf['title'] : nons($ns))." index\n\n";
            unset($renderer->persistent['indexmenu']);
            return true;

        } else {
            return false;
        }
    }

    /**
     * Return the index
     *
     * @param string $ns
     * @param array $js_dTreeOpts entries: theme, identifier, nocookie, navbar, noscroll, maxjs, notoc, jsajax, context, nomenu
     * @param array $sort entries: sort, msort, rsort, nsort, hsort
     * @param array $opts entries of opts for search(): level, nons, nopg, nss, max, maxajax, js, skipns, skipfile, headpage, hide_headpage
     * @param int $jsVersion
     * @return bool|string return html for a nojs index and when enabled the js rendered index, otherwise false
     *
     * @author Samuele Tognini <samuele@samuele.netsons.org>
     */
    private function buildHtmlIndexmenu($ns, $js_dTreeOpts, $sort, $opts, $jsVersion) {
        $js_name = "indexmenu_".$js_dTreeOpts['identifier'];
        $opts['tempNew'] = false; //TODO temporary hack, to switch in Search between searchIndexmenuItemsNew() and searchIndexmenuItems()
        $search = new Search($sort);
        $data = $search->search($ns, $opts);

        if(!$data) return false;

        // javascript index
        $output_js = "";
        if($opts['js']) {
            $ns         = str_replace('/', ':', $ns);
            $output_js = '';

            // $jsversion: 0:both, 1:dTree, 2:Fancytree
            if($jsVersion < 2) {
                $output_js .= $this->builddTree($data, $ns, $js_dTreeOpts, $js_name, $opts['max']);
            }
            if($jsVersion !== 1) {
                $output_js .= $this->buildFancyTree($js_name, $ns, $opts, $sort);
            }

            //remove unwanted nodes from standard index
            $this->cleanNojsData($data);
        }
        $output = "\n";
        $output .= $this->buildNoJSTree($data, $js_name, $js_dTreeOpts['jsajax']);
        $output .=  $output_js;
        return $output;
    }

    private function buildNoJSTree($data, $js_name, $jsAjax) {
        // Nojs dokuwiki index
        //    extra div needed when index is first element in sidebar of dokuwiki template, template uses this to toggle sidebar
        //    the toggle interacts with hide needed for js option.
        return '<div>'
            . '<div id="nojs_'.$js_name.'" data-jsajax="'.utf8_encodeFN($jsAjax).'" class="indexmenu_nojs">'."\n"
            . html_buildlist($data, 'idx', array($this, "formatIndexmenuItem"), "html_li_index")
            . '</div>'
            . '</div>'."\n";
    }

    private function buildFancyTree($js_name, $ns, $opts, $sort) {
        //not needed, because directly retrieved from config
        unset($opts['headpage']);
        unset($opts['hide_headpage']);

        $options = [
            'ns' => $ns,
            'opts' => $opts,
            'sort' => $sort,
            'contextmenu' => false
        ];
        return '<div id="tree2_'.$js_name.'" class="indexmenu_js2 skin-'.$opts['theme'].'" data-options=\''.json_encode($options).'\'></div>';
    }

    /**
     * Build the browsable index of pages using javascript
     *
     * @param array  $data    array with items of the tree
     * @param string $ns      requested namespace
     * @param array  $js_dTreeOpts options for javascript renderer
     * @param string $js_name identifier for this index
     * @param int    $max     the node at $max level will retrieve all its child nodes through the AJAX mechanism
     * @return bool|string returns inline javascript or false
     *@author  Samuele Tognini <samuele@samuele.netsons.org>
     * @author  Rene Hadler
     *
     */
    private function builddTree($data, $ns, $js_dTreeOpts, $js_name, $max) {
        global $conf;
        $hns = false;
        if(empty($data)) return false;
//error_log(print_r($js_dTreeOpts,true));
//TODO jsajax is empty?? while max is set to 1
        //Render requested ns as root
        $headpage = $this->getConf('headpage');
        //if rootnamespace and headpage, then add startpage as headpage - TODO seems not logic, when desired use $conf[headpage]=:start: ??
        if(empty($ns) && !empty($headpage)) {
            $headpage .= ','.$conf['start'];
        }
        $search = new Search(['sort'=>false, 'msort'=>false, 'rsort'=>false, 'nsort'=>false, 'hsort'=>false]);
        $title = $search->getNamespaceTitle($ns, $headpage, $hns); //TODO static function?
        if(empty($title)) {
            if(empty($ns)){
                $title = htmlspecialchars($conf['title'], ENT_QUOTES);
            } else{
                $title = $ns;
            }
        }
        // inline javascript
        $out = "<script type='text/javascript' charset='utf-8'>\n";
        $out .= "<!--//--><![CDATA[//><!--\n";
        $out .= "var $js_name = new dTree('".$js_name."','".$js_dTreeOpts['theme']."');\n";
        //javascript config options
        $sepchar = idfilter(':', false);
        $out .= "$js_name.config.urlbase='".substr(wl(":"), 0, -1)."';\n";
        $out .= "$js_name.config.sepchar='".$sepchar."';\n";
        if($js_dTreeOpts['notoc'])          $out .= "$js_name.config.toc=false;\n";
        if($js_dTreeOpts['nocookie'])       $out .= "$js_name.config.useCookies=false;\n";
        if($js_dTreeOpts['noscroll'])       $out .= "$js_name.config.scroll=false;\n";
        if($js_dTreeOpts['maxjs'] > 1)      $out .= "$js_name.config.maxjs=".$js_dTreeOpts['maxjs'].";\n"; //1 is default in dTree
        if(!empty($js_dTreeOpts['jsajax'])) $out .= "$js_name.config.jsajax='".utf8_encodeFN($js_dTreeOpts['jsajax'])."';\n";

        //add root node
        $out .= $js_name.".add('".idfilter(cleanID($ns), false)."',0,-1,".json_encode($title);
        if($hns) {
            $out .= ",'".idfilter(cleanID($hns), false)."'";
        }
        $out .= ");\n";
        //add nodes
        $anodes = $this->builddTreeNodes($data, $js_name);
        $out .= $anodes[0];
        //write to document
        $out .= "document.write(".$js_name.");\n";
        //initialize index
        $out .= "jQuery(function(){".$js_name.".init(";
        $out .= (int) is_file(INDEXMENU_IMG_ABSDIR.'/'.$js_dTreeOpts['theme'].'/style.css').",";
        $out .= (int) $js_dTreeOpts['nocookie'].",";
        $out .= '"'.$anodes[1].'",';
        $out .= (int) $js_dTreeOpts['navbar'].",";
        $out .= (int) $max;
        if($js_dTreeOpts['nomenu']) {
            $out .= ",1";
        }
        $out .= ");});\n";

        $out .= "//--><!]]>\n";
        $out .= "</script>\n";
        return $out;
    }

    /**
     * Return array of javascript nodes and nodes to open.
     *
     * @author  Samuele Tognini <samuele@samuele.netsons.org>
     * @param array  $data    array with items of the tree
     * @param string $js_name identifier for this index
     * @param boolean $noajax  return as inline js (=true) or array for ajax response (=false)
     * @return array|bool returns array with
     *     - a string of the javascript nodes
     *     - and a string of space separated numbers of the opened nodes
     *    or false when no data provided
     */
    public function builddTreeNodes($data, $js_name, $noajax = true) {
        if(empty($data)) return false;
        //Array of nodes to check
        $q = array('0');
        //Current open node
        $node  = 0;
        $out   = '';
        $opennodes = '';
        if($noajax) {
            $jscmd = $js_name.".add";
            $separator   = ";\n";
        } else {
            $jscmd = "new Array ";
            $separator   = ",";
        }

        foreach($data as $i=> $item) {
            $i++;
            //Remove already processed nodes (greater level = lower level)
            while($item['level'] <= $data[end($q) - 1]['level']) {
                array_pop($q);
            }

            //till i found its father node
            if($item['level'] == 1) {
                //root node
                $father = '0';
            } else {
                //Father node
                $father = end($q);
            }
            //add node and its options
            if($item['type'] == 'd') {
                //Search the lowest open node of a tree branch in order to open it.
                if($item['open']) {
                    if($item['level'] < $data[$node]['level']) {
                        $node = $i;
                    } else {
                        $opennodes .= "$i ";
                    }
                }
                //insert node in last position
                array_push($q, $i);
            }
            $out .= $jscmd."('".idfilter($item['id'], false)."',$i,".$father.",".json_encode($item['title']);
            //hns
            if($item['hns']) {
                $out .= ",'".idfilter($item['hns'], false)."'";
            } else {
                $out .= ",0";
            }
            if($item['type'] == 'd' || $item['type'] == 'l') {
                $out .= ",1";
            } else {
                $out .= ",0";
            }
            //MAX option
            if($item['type'] == 'l') {
                $out .= ",1";
            }else{
                $out .= ",0";
            }
            $out .= ")".$separator;
        }
        $opennodes = rtrim($opennodes, ' ');
        return array($out, $opennodes);
    }

    /**
     * Parse namespace request
     *
     * @author  Samuele Tognini <samuele@samuele.netsons.org>
     * @param string $ns namespaceid
     * @param bool   $id page id to resolve $ns relative to.
     * @return string id of namespace
     */
    public function parseNs($ns, $id = false) {
        if(!$id) {
            global $ID;
            $id = $ID;
        }
        //Just for old releases compatibility
        if(empty($ns) || $ns == '..') {
            $ns = ":..";
        }
        return resolve_id(getNS($id), $ns);
    }

    /**
     * Clean index data from unwanted nodes in nojs mode.
     *
     * @author  Samuele Tognini <samuele@samuele.netsons.org>
     * @param array $data nodes of the tree
     * @return void
     */
    private function cleanNojsData(&$data) {
        foreach($data as $i=> $item) {
            //closed node
            if($item['type'] == "d" && !$item['open']) {
                $a     = $i + 1;
                $level = $item['level'];
                //search and remove every lower and closed nodes
                while($data[$a]['level'] > $level && !$data[$a]['open']) {
                    unset($data[$a]);
                    $a++;
                }
            }
        }
    }


    /**
     * Callback to print a Indexmenu item
     *
     * User function for @see html_buildlist()
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     * @author Samuele Tognini <samuele@samuele.netsons.org>
     * @author Rik Blok
     *
     * @param array $item item described by array with at least the entries
     *          - id    page id/namespace id
     *          - type  'd', 'l'(directory which is not yet opened) or 'f'
     *          - open  is node open
     *          - title title of link
     *          - hns   page id of headpage of the namespace or false
     * @return string html of the content of a list item
     */
    public function formatIndexmenuItem($item) {
        global $INFO;
        $ret = '';

        //namespace
        if($item['type'] == 'd' || $item['type'] == 'l') {
            $markCurrentPage = false;

            $link = $item['id'];
            $more = 'idx='.$item['id'];
            //namespace link
            if($item['hns']) {
                $link  = $item['hns'];
                $tagid = "indexmenu_idx_head";
                $more  = '';
                //current page is shown?
                $markCurrentPage = $this->getConf('hide_headpage') && $item['hns'] == $INFO['id'];
            } else {
                //namespace without headpage
                $tagid = "indexmenu_idx";
                if($item['open']) {
                    $tagid .= ' open';
                }
            }

            if($markCurrentPage) {
                $ret .= '<span class="curid">';
            }
            $ret .= '<a href="'.wl($link, $more).'" class="'.$tagid.'">'
                . $item['title']
                . '</a>';
            if($markCurrentPage) {
                $ret .= '</span>';
            }
            return $ret;
        } else {
            //page link
            return html_wikilink(':'.$item['id']);
        }
    }

}
