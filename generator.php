<?php 
// adjust or remove the namespace data as needed...
namespace Muster;

use StdClass;
use Kohana;

// adjust or remove the defined statement as needed...
defined('SYSPATH') or die('No direct script access.');

// Muster Software Copyright (c) Henrik Bechmann, Toronto, Canada 2012. All rights reserved.
// BSD licence. See "musterlicence.txt" for licencing information.
// mustersoftware.net
/* 	This is an implementation and extension of the mustache design pattern: 
		http://mustache.github.com/mustache.5.html

Basically: 
{{...}} connotes a variable (no closing tag, escaped; {{&...}} is unescaped) eg. {{totalsales}}
{{#...}} connotes a section (with a closing tag - {{/#...}}) eg. {{#recordset}}...{{/#recordset}}

General form {{<symbols>index}} ... {{/<symbols>index}}
variables do not require closing tag
indexes must start with alpha character and be followed by word characters (letters, digits, "_")

sections can be thought of as nested namespaces

delimiters:
{{...}} these cannot be changed (but it wouldn't be hard to extend the class to allow alternate delimiters)
symbols:
section qualifier:
#
alternate qualifier:
^ = inversion (IS NOT - replaces #)
additional qualifiers: 
& = unescaped variable
? = assertion (IS) for var, #
* = global (root dictionary) for var, #, ?, ^
specialized:
> = partial (template fragment)
! = comment (thrown away)
reserved:
+ = subsection (template recursion - reserved - not implemented)

Markup:
	Notes:
		* = global = reference to dictionary root associative array
		alternate, simpler, element closing tags are DEPRECATED, but available for MUSTACHE compatibility
assertions:	
	{{?*...}} ... {{/?*...}} or {{/...}}		global var assertion: exists, and true or not empty or numeric (0)
	{{?...}} ... {{/?...}} or {{/...}} 			var assertion: exists, and true or not empty or numeric (0)
	{{#?*...}} ... {{/#?*...}} or {{/?...}}		global section assertion: exists, and true or not empty or numeric (0)
	{{#?...}} ... {{/#?...}} or {{/?...}}		section assertion: exists, and true or not empty or numeric (0)
namespaces (nested sections):
	{{#*...}} ... {{/#*...}} or {{/...}}		global section
	{{#...}} ... {{/#...}} or {{/...}}			section: own scope of object or array, or variable assertion
inversions:
	{{^*...}} ... {{/^*...}} or {{/...}}		global inverted section or variable: doesn't exist, or false, or empty and not numeric (0)
	{{^...}} ... {{/^...}} or {{/...}}			inverted section or variable: doesn't exist, or false, or empty and not numeric (0)
specialized:
	{{>...}}									partial template: index taken as file name unless dictionary entry is set
	{{!...}}									comment: thrown away
variables:
	{{&*...}}									unescaped global var: no htmlspecialchar filter
	{{*...}}									global var: htmlspecialchar filter
	{{&...}}									unescaped var: no htmlspecialchar filter
	{{...}}										var: htmlspecialchar filter
	
template is combined with dictionary through markup.

dictionary: 
	root must be associative array
	nested values can be 
		basic datatypes, or 
		simple objects (interpreted as nested namespaces, converted to associative arrays)
		list arrays, which are iterated with parallel section template fragment

template fragments and dictionary are processed and recursed in tandem

Generator does not support function data type: preprocess dictionary instead.
*/
class Generator
{
	protected $_rules;
	protected $_re;
	protected $_starting_template;
	protected $_starting_dictionary;
	protected $_dictionaries = array();
	protected $_dictionary; // current template context
	protected $_template_cache = array(); // for partials

	# ========================================================================= #
	# -------------------------[ static functions ]-----------------------------#
	# ========================================================================= #
	
	// get instance; alias of factory
	public static function view($template,$dictionary = NULL)
	{
		return self::factory($template,$dictionary);
	}
	// get instance
	public static function factory($template,$dictionary)
	{
		return new self($template,$dictionary);
	}
	// get dictionary
	public static function get_dictionary($filename,$directory = 'frames')
	{
		$file = self::find_file($directory,$filename);
		if ($file)
			return include $file;
		else
			return array();
	}
	// get template
	// $filename can be 2-element array('filename'=>$filename,'ext'=>$ext) 
	//  in which case $extension parameter is ignored
	public static function get_template($filename,$directory = 'views',$extension = 'html')
	{
		$filedata = (array) $filename;
		if (count(($filedata) == 1) and isset($filedata[0])) {
			$file = self::find_file($directory,$filedata[0],$extension);
		} else {
			$filename = isset($filedata['filename'])?$filedata['filename']:NULL;
			$ext = isset($filedata['ext'])?$filedata['ext']:$extension;
			if ($filename and $ext)
				$file = self::find_file($directory,$filename,$ext);
			else return '';
		}
		if ($file)
			return file_get_contents($file);
		else return '';
	}
	// isolates Kohana find_file dependency - can over-ride with subclass
	public static function find_file($directory,$filename,$file_extension = NULL)
	{
		return Kohana::find_file($directory,$filename,$file_extension);
	}

	# ========================================================================= #
	# -------------------------[ public functions ]-----------------------------#
	# ========================================================================= #
	
	public function __construct($template,$dictionary)
	{
		if (is_array($template))
			$template = $this->_get_template($template);
		if (is_string($dictionary))
			$dictionary = $this->_get_dictionary($dictionary);
		$this->_starting_template = $template;
		$this->_starting_dictionary = $dictionary;
		
		$this->_set_rules();
	}
	// this allows a Generator object to be assigned to another template's variable
	public function __toString()
	{
		return $this->render();
	}
	// combine dictionary values with template
	public function expand()
	{
		if ($this->_starting_template and $this->_starting_dictionary)
			return $this->_expand($this->_starting_template,$this->_starting_dictionary);
		return $this->_starting_template;
	}
	// alias of expand
	public function render()
	{
		return $this->expand();
	}
	// modify base dictionary - global variable - 
	// with simple assignment
	public function __set($index,$value)
	{
		$this->_starting_dictionary[$index]=$value;
	}
	// query dictionary - global variable
	public function __get($index)
	{
		if (isset($this->_starting_dictionary[$index]))
			return $this->_starting_dictionary[$index];
		else return NULL;
	}
	
	# ========================================================================= #
	# -------------------------[ processing support ]---------------------------#
	# ========================================================================= #
	
	protected function _set_rules()
	{ // uses /s flag to avoid need for \n test
		$rules = new StdClass;
		# {{?*...}} ... {{/?*...}}
		$rules->globalassertion = '(?P<globalassertion>
		\{\{\?\*(?P<globalassertion_index>[a-zA-Z]\w*)[\t\x20]*\}\} 
		(?<globalassertion_block>.*?)
		\{\{\/(\?\*)?(?P=globalassertion_index)[\t\x20]*\}\}
		)';
		# {{?...}} ... {{/?...}}
		$rules->assertion = '(?P<assertion>
		\{\{\?(?P<assertion_index>[a-zA-Z]\w*)[\t\x20]*\}\} 
		(?<assertion_block>.*?)
		\{\{\/(\?)?(?P=assertion_index)[\t\x20]*\}\}
		)';
		# {{#?*...}} ... {{/#?*...}}
		$rules->globalsectionassertion = '(?P<globalsectionassertion>
		\{\{\#\?\*(?P<globalsectionassertion_index>[a-zA-Z]\w*)[\t\x20]*\}\} 
		(?<globalsectionassertion_block>.*?)
		\{\{\/[#]?\?[*]?(?P=globalsectionassertion_index)[\t\x20]*\}\}
		)';
		# {{#?...}} ... {{/#?...}}
		$rules->sectionassertion = '(?P<sectionassertion>
		\{\{\#\?(?P<sectionassertion_index>[a-zA-Z]\w*)[\t\x20]*\}\} 
		(?<sectionassertion_block>.*?)
		\{\{\/[#]?\?(?P=sectionassertion_index)[\t\x20]*\}\}
		)';
		# {{#*...}} ... {{/#*...}}
		$rules->globalsection = '(?P<globalsection>
		\{\{\#\*(?P<globalsection_index>[a-zA-Z]\w*)[\t\x20]*\}\} 
		(?<globalsection_block>.*?)
		\{\{\/(\#\*)?(?P=globalsection_index)[\t\x20]*\}\}
		)';
		# {{#...}} ... {{/#...}}
		$rules->section = '(?P<section>
		\{\{\#(?P<section_index>[a-zA-Z]\w*)[\t\x20]*\}\} 
		(?<section_block>.*?)
		\{\{\/[#]?(?P=section_index)[\t\x20]*\}\}
		)';
		# {{^*...}} ... {{/^*...}}
		$rules->globalinverted_section = '(?P<globalinverted_section>
		\{\{\^\*(?P<globalinverted_section_index>[a-zA-Z]\w*)[\t\x20]*\}\} 
		(?<globalinverted_section_block>.*?)
		\{\{\/(\^\*)?(?P=globalinverted_section_index)[\t\x20]*\}\}
		)';
		# {{^...}} ... {{/^...}}
		$rules->inverted_section = '(?P<inverted_section>
		\{\{\^(?P<inverted_section_index>[a-zA-Z]\w*)[\t\x20]*\}\} 
		(?<inverted_section_block>.*?)
		\{\{\/(\^)?(?P=inverted_section_index)[\t\x20]*\}\}
		)';
		# {{>...}}
		$rules->partial = '(?P<partial>
		\{\{>(?P<partial_index>[a-zA-Z]\w*)[\t\x20]*\}\}
		)';
		# {{!...}}
		$rules->comment = '(?P<comment>
		\{\{!.*?\}\}
		)';
		# {{&*...}}
		$rules->unescaped_globalvar = '(?P<unescaped_globalvar>
		\{\{\&\*(?P<unescaped_globalvar_index>[a-zA-Z]\w*)[\t\x20]*\}\}
		)';
		# {{*...}}
		$rules->globalvar = '(?P<globalvar>
		\{\{\*(?P<globalvar_index>[a-zA-Z]\w*)[\t\x20]*\}\}
		)';
		# {{&...}}
		$rules->unescaped_variable = '(?P<unescaped_variable>
		\{\{\&(?P<unescaped_variable_index>[a-zA-Z]\w*)[\t\x20]*\}\}
		)';
		# {{...}}
		$rules->variable = '(?P<variable>
		\{\{(?P<variable_index>[a-zA-Z]\w*)[\t\x20]*\}\}
		)';
		// command chain pattern
		$this->_re =  "/\n" 
			. implode("\n|\n",array(
			$rules->globalassertion,
			$rules->assertion,
			$rules->globalsectionassertion,
			$rules->sectionassertion,
			$rules->globalsection,
			$rules->section,
//			$rules->subsection,
			$rules->globalinverted_section,
			$rules->inverted_section,
			$rules->partial,
			$rules->comment,
			$rules->unescaped_globalvar,
			$rules->globalvar,
			$rules->unescaped_variable,
			$rules->variable,
			)) . "\n/sx";
	}
	// the (recursive) entry point
	// must not generate exception - __toString can't handle exception.
	protected function _expand($template, Array $dictionary) // indirectly recursive
	{
		// stack dictionary
		array_push($this->_dictionaries,$dictionary);
		// set context
		$this->_dictionary = end($this->_dictionaries);
		// process template
		$retval = preg_replace_callback($this->_re,array($this,'_expand_templatepoint'),$template);
		// restore context
		array_pop($this->_dictionaries);
		$this->_dictionary = end($this->_dictionaries); // may be FALSE when completed
		// return merged template
		return $retval;
	}
	// preg callback
	protected function _expand_templatepoint($preg_groups)
	{
		$processing_method = '';
		// find first preg variable
		foreach ($preg_groups as $key => $value) {
			if ((!is_numeric($key)) and ($value)) {
				$processing_method = '_process_' . $key;
				break;
			}
		}
		if ($processing_method)
			return $this->$processing_method($preg_groups);
		else return ''; // empty block
	}
	protected function _get_template($filedata)
	{
		return self::get_template($filedata);
	}
	protected function _get_dictionary($filedata)
	{
		return self::get_dictionary($filedata);
	}
	
	# ========================================================================= #
	# -------------------------[ markup processing ]----------------------------#
	# ========================================================================= #
	
	# {{?*...}} ... {{/?*...}}
	// acts like IF
	protected function _process_globalassertion($preg_groups)
	{
		$index = $preg_groups['globalassertion_index'];
		$dictionary = $this->_starting_dictionary;
		if (isset($dictionary[$index])) {
			$term = $dictionary[$index];
			if (!empty($term) or is_numeric($term)) { // is_numeric for '0'
				return $this->_expand($preg_groups['globalassertion_block'],$this->_dictionary);
			}
		} 
		return '';
	}
	# {{?...}} ... {{/?...}}
	// acts like IF
	protected function _process_assertion($preg_groups)
	{
		$index = $preg_groups['assertion_index'];
		$dictionary = $this->_dictionary;
		if (isset($dictionary[$index])) {
			$term = $dictionary[$index];
			if (!empty($term) or is_numeric($term)) { // is_numeric for '0'
				return $this->_expand($preg_groups['assertion_block'],$this->_dictionary);
			}
		}
		return '';
	}
	# {{#?*...}} ... {{/#?*...}}
	// acts like IF NOT EMPTY
	protected function _process_globalsectionassertion($preg_groups)
	{
		$index = $preg_groups['globalsectionassertion_index'];
		$dictionary = $this->_starting_dictionary;
		if (isset($dictionary[$index])) { 
			$term = $dictionary[$index];
			if (!empty($term) or is_numeric($term)) { // is_numeric for '0'
				return $this->_expand($preg_groups['globalsectionassertion_block'],$this->_dictionary);
			}
		} 
		return '';
	}
	# {{#?...}} ... {{/#?...}}
	// acts like IF NOT EMPTY
	protected function _process_sectionassertion($preg_groups)
	{
		$index = $preg_groups['sectionassertion_index'];
		$dictionary = $this->_dictionary;
		if (isset($dictionary[$index])) {
			$term = $dictionary[$index];
			if (!empty($term) or is_numeric($term)) { // is_numeric for '0'
				return $this->_expand($preg_groups['sectionassertion_block'],$this->_dictionary);
			}
		}
		return '';
	}
	# {{#*...}} ... {{/#*...}}
	protected function _process_globalsection($preg_groups)
	{
		$index = $preg_groups['globalsection_index'];
		$dictionary = $this->_starting_dictionary;
		$term = isset($dictionary[$index])? $dictionary[$index]:NULL;
		$template = $preg_groups['globalsection_block'];
		$retval = $this->_process_section_data($term,$template);
		return $retval;
	}
	# {{#...}} ... {{/#...}}
	protected function _process_section($preg_groups)
	{
		$index = $preg_groups['section_index'];
		$dictionary = $this->_dictionary;
		$term = isset($dictionary[$index])? $dictionary[$index]:NULL;
		$template = $preg_groups['section_block'];
		$retval = $this->_process_section_data($term,$template);
		return $retval;
	}
	// _process_section_data supports both process_global_section and process_section
	/* it differentiates:
		list array (iterates)
		namespace (simple object = associative array)
		simple variable acts as assertion
	*/
	protected function _process_section_data($term,$template) // can act as var assertion
	{
		if ((!empty($term)) or is_numeric($term)) { // is_numeric for '0'
			if (is_array($term)) { // list; records; iterate
				$retval = '';
				foreach ($term as $item) {
					is_object($item) and ($item = (array) $item);
					$retval .= $this->_expand($template,$item);
				}
				return $retval;
			} elseif (is_object($term)) { // associative array; namespace
				$term = (array) $term;
				return $this->_expand($template,$term);
			} else { // simple variable acts as assertion
				return $this->_expand($template,$this->_dictionary);
			}
		} 
		return '';
	}
	# {{^*...}} ... {{/^*...}}
	// acts like IF NOT when EMPTY or FALSE (and not "0")
	protected function _process_globalinverted_section($preg_groups)
	{
		$index = $preg_groups['globalinverted_section_index'];
		$dictionary = $this->_starting_dictionary;
		if (!isset($dictionary[$index])) {
			$term = NULL;
		} else {
			$term = $dictionary[$index];
		}
		if (empty($term) and !is_numeric($term)) { // not '0'
			return $this->_expand($preg_groups['globalinverted_section_block'],$this->_dictionary);
		} 
		return '';
	}
	# {{^...}} ... {{/^...}}
	// acts like IF NOT when EMPTY or FALSE (and not "0")
	protected function _process_inverted_section($preg_groups)
	{
		$index = $preg_groups['inverted_section_index'];
		$dictionary = $this->_dictionary;
		if (!isset($dictionary[$index])) {
			$term = NULL;
		} else {
			$term = $dictionary[$index];
		}
		if (empty($term) and !is_numeric($term)) { // not '0'
			return $this->_expand($preg_groups['inverted_section_block'],$this->_dictionary); // $dictionary);
		} 
		return '';
	}
	# {{>...}}
	// uses the index as filename unless dictionary entry found
	protected function _process_partial($preg_groups)
	{
		$index = $preg_groups['partial_index'];
		$dictionary = $this->_dictionary;
		if (isset($dictionary[$index])) {// dictionary entry, can be template of array(templatefilename)
			// allow unique templates for each dictionary, ie for each list iteration
			// but all references to $index must be in dictionary
			$template = $dictionary[$index]; 
		} else {
			$template = array($index);
		}
		if (is_array($template)) {// requires isset($template[0])
			$diskindex = $template[0];
			$cache = $this->_template_cache;
			if (isset($cache[$diskindex])) {
				$template = $cache[$diskindex];
			} else {
				$template = $this->_get_template($template);
				if (empty($template)) return '';
				$this->_template_cache[$diskindex] = $template; // anticipate more references; avoid file seeks
			}
		}
		return $this->_expand($template,$this->_dictionary);
	}
	# {{!...}}
	protected function _process_comment($preg_groups)
	{
		return ''; // ignore, per mustache specs
	}
	# {{&*...}}
	protected function _process_unescaped_globalvar($preg_groups)
	{
		$index = $preg_groups['unescaped_globalvar_index'];
		$dictionary = $this->_starting_dictionary;
		$term = isset($dictionary[$index])? $dictionary[$index]: '';
		// defensive filter
		if (is_array($term))
			return htmlspecialchars(var_export($term, TRUE));
		return $term;
	}
	# {{*...}}
	protected function _process_globalvar($preg_groups)
	{
		$index = $preg_groups['globalvar_index'];
		$dictionary = $this->_starting_dictionary;
		$term = isset($dictionary[$index])? $dictionary[$index]: '';
		// defensive filter
		if (is_array($term))
			return htmlspecialchars(var_export($term, TRUE));
		return htmlspecialchars($term);
	}
	# {{&...}}
	protected function _process_unescaped_variable($preg_groups)
	{
		$index = $preg_groups['unescaped_variable_index'];
		$dictionary = $this->_dictionary;
		$term = isset($dictionary[$index])? $dictionary[$index]: '';
		// defensive filter
		if (is_array($term))
			return htmlspecialchars(var_export($term, TRUE));
		return $term;
	}
	# {{...}}
	protected function _process_variable($preg_groups)
	{
		$index = $preg_groups['variable_index'];
		$dictionary = $this->_dictionary;
		$term = isset($dictionary[$index])? $dictionary[$index]: '';
		// defensive filter
		if (is_array($term))
			return htmlspecialchars(var_export($term, TRUE));
		return htmlspecialchars($term);
	}
}
