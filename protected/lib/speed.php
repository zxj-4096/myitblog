<?php
//set_error_handler("_err_handle");
defined('DS') or define('DS', DIRECTORY_SEPARATOR);
$GLOBALS = require(APP_DIR.'/protected/config.php');

spl_autoload_register('inner_autoload');
function inner_autoload($class){
	GLOBAL $__module;
	foreach(array('model', 'include', 'controller'.(empty($__module)?'':DS.$__module)) as $dir){
		$file = APP_DIR.DS.'protected'.DS.$dir.DS.$class.'.php';
		if(file_exists($file)){
			include $file;
			return;
		}
		$lowerfile = strtolower($file);
		foreach(glob(APP_DIR.DS.'protected'.DS.$dir.DS.'*.php') as $file){
			if(strtolower($file) === $lowerfile){
				include $file;
				return;
			}
		}
	}
}

$_REQUEST = array_merge($_POST, $_GET);
$__module     = isset($_REQUEST['m']) ? strtolower($_REQUEST['m']) : '';
$__controller = isset($_REQUEST['c']) ? strtolower($_REQUEST['c']) : 'main';
$__action     = isset($_REQUEST['a']) ? strtolower($_REQUEST['a']) : 'index';

$controller_name = $__controller.'Controller';
$action_name = 'action'.$__action;
$controller_obj = new $controller_name();
$controller_obj->$action_name();
if($controller_obj->_auto_display){
	$auto_tpl_name = (empty($__module) ? '' : $__module.DS).$__controller.'_'.$__action.'.html';
	if(file_exists(APP_DIR.DS.'protected'.DS.'view'.DS.$auto_tpl_name)){	
		$controller_obj->display($auto_tpl_name);
	}
}

class Controller{
	public $layout;
	public $_auto_display = true;
	private $_v;
	private $_data = array();

	public function init(){}
	public function __construct(){$this->init();}
	public function __get($name){return $this->_data[$name];}
	public function __set($name, $value){$this->_data[$name] = $value;}
	
	public function display($tpl_name, $return = false){
		if(!$this->_v) $this->_v = new View(APP_DIR.DS.'protected'.DS.'view', APP_DIR.DS.'protected'.DS.'tmp');
		$this->_v->assign(get_object_vars($this));
		$this->_v->assign($this->_data);
		if($this->layout){
			$this->_v->assign('__template_file', $tpl_name);
			$tpl_name = $this->layout;
		}
		$this->_auto_display = false;
		
		if($return){
			return $this->_v->render($tpl_name);
		}else{
			echo $this->_v->render($tpl_name);
		}
	}
}

class View{
	private $left_delimiter, $right_delimiter, $template_dir, $compile_dir;
	private $template_vals = array();
	
	public function __construct($template_dir, $compile_dir, $left_delimiter = '<{', $right_delimiter = '}>'){
		$this->left_delimiter = $left_delimiter; 
		$this->right_delimiter = $right_delimiter;
		$this->template_dir = $template_dir;     
		$this->compile_dir  = $compile_dir;
	}
	
	public function render($tempalte_name){
		$complied_file = $this->compile($tempalte_name);
		@ob_start();
		extract($this->template_vals, EXTR_SKIP);
		$_view_obj = & $this;
		include $complied_file;
		return ob_get_clean();
	} 
	
	public function assign($mixed, $val = ''){
        if(is_array($mixed)){
            foreach($mixed as $k => $v){
                if($k != '')$this->template_vals[$k] = $v;
            }
        }else{
            if($mixed != '')$this->template_vals[$mixed] = $val;
        }
	}

	public function compile($tempalte_name){
		$file = $this->template_dir.DS.$tempalte_name;
		if(!file_exists($file)) err('Err: "'.$file.'" is not exists!');
		if(!is_writable($this->compile_dir) || !is_readable($this->compile_dir)) err('Err: Directory "'.$this->compile_dir.'" is not writable or readable');

		$complied_file = $this->compile_dir.DS.md5(realpath($file)).'.'.filemtime($file).'.'.basename($tempalte_name).'.php';
		if(file_exists($complied_file))return $complied_file;

		$template_data = file_get_contents($file); 
		$template_data = $this->_compile_struct($template_data);
		$template_data = $this->_compile_function($template_data);
		$template_data = '<?php if(!class_exists("View", false)) exit("no direct access allowed");?>'.$template_data;
		
		$this->_clear_compliedfile($tempalte_name);
		file_put_contents($complied_file, $template_data);
		
		return $complied_file;
	}

	private function _compile_struct($template_data){
		$foreach_inner_before = '<?php $_foreach_$3_counter = 0; $_foreach_$3_total = count($1);?>';
		$foreach_inner_after  = '<?php $_foreach_$3_index = $_foreach_$3_counter;$_foreach_$3_iteration = $_foreach_$3_counter + 1;$_foreach_$3_first = ($_foreach_$3_counter == 0);$_foreach_$3_last = ($_foreach_$3_counter == $_foreach_$3_total);$_foreach_$3_counter++;?>';
		$pattern_map = array(
			'<{\*([\s\S]+?)\*}>'      => '<?php /* $1*/?>',
			'(<{((?!}>).)*?)(\$[\w\_\"\'\[\]]+?)\.(\w+)(.*?}>)' => '$1$3[\'$4\']$5',
			'(<{.*?)(\$(\w+)@(index|iteration|first|last|total))+(.*?}>)' => '$1$_foreach_$3_$4$5',
			'<{(\$[\S]+?)}>'          => '<?php echo $1; ?>',
			'<{(\$[\S]+?)\s*=(.*?)\s*}>'           => '<?php $1 =$2; ?>',
			'<{(\$[\S]+?)\snofilter\s*}>'          => '<?php echo htmlspecialchars($1, ENT_QUOTES, "UTF-8"); ?>',
			'<{if\s*(.+?)}>'          => '<?php if ($1) : ?>',
			'<{else\s*if\s*(.+?)}>'   => '<?php elseif ($1) : ?>',
			'<{else}>'                => '<?php else : ?>',
			'<{break}>'               => '<?php break; ?>',
			'<{continue}>'            => '<?php continue; ?>',
			'<{\/if}>'                => '<?php endif; ?>',
			'<{foreach\s*(\$[\w\.\_\"\'\[\]]+?)\s*as(\s*)\$([\w\_\"\'\[\]]+?)}>' => $foreach_inner_before.'<?php foreach( $1 as $$3 ) : ?>'.$foreach_inner_after,
			'<{foreach\s*(\$[\w\.\_\"\'\[\]]+?)\s*as\s*(\$[\w\_\"\'\[\]]+?)\s*=>\s*\$([\w\_\"\'\[\]]+?)}>'  => $foreach_inner_before.'<?php foreach( $1 as $2 => $$3 ) : ?>'.$foreach_inner_after,
			'<{\/foreach}>'           => '<?php endforeach; ?>',
			'<{include\s*file=(.+?)}>'=> '<?php include $_view_obj->compile($1); ?>',
		);
		$pattern = $replacement = array();
		foreach($pattern_map as $p => $r){
			$pattern = '/'.str_replace(array("<{", "}>"), array($this->left_delimiter.'\s*','\s*'.$this->right_delimiter), $p).'/i';
			$count = 1;
			while($count != 0){
				$template_data = preg_replace($pattern, $r, $template_data, -1, $count);
			}
		}
		return $template_data;
	}
	
	private function _compile_function($template_data){
		$pattern = '/'.$this->left_delimiter.'([\w_]+)\s*(.*?)'.$this->right_delimiter.'/';
		return preg_replace_callback($pattern, array($this, '_compile_function_callback'), $template_data);
	}
	
	private function _compile_function_callback( $matches ){
		if(empty($matches[2]))return '<?php echo '.$matches[1].'();?>';
		$sysfunc = preg_replace('/\((.*)\)\s*$/', '<?php echo '.$matches[1].'($1);?>', $matches[2], -1, $count);
		if($count)return $sysfunc;
		
		$pattern_inner = '/\b([\w_]+?)\s*=\s*(\$[\w"\'\]\[\-_>\$]+|"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\')\s*?/'; 
		$params = "";
		if(preg_match_all($pattern_inner, $matches[2], $matches_inner, PREG_SET_ORDER)){
			$params = "array(";
			foreach($matches_inner as $m)$params .= '\''. $m[1]."'=>".$m[2].", ";
			$params .= ")";
		}else{
			err('Err: Parameters of \''.$matches[1].'\' is incorrect!');
		}
		return '<?php echo '.$matches[1].'('.$params.');?>';
	}

	private function _clear_compliedfile($tempalte_name){
		$dir = scandir($this->compile_dir);
		if($dir){
			$part = md5(realpath($this->template_dir.DS.$tempalte_name));
			foreach($dir as $d){
				if(substr($d, 0, strlen($part)) == $part){
					@unlink($this->compile_dir.DS.$d);
				}
			}
		}
	}
}
function _err_handle($errno, $errstr, $errfile, $errline){
	if(0 === error_reporting())return false;
	$msg = "ERROR";
	if($errno == E_WARNING)$msg = "WARNING";
	if($errno == E_NOTICE)$msg = "NOTICE";
	if($errno == E_STRICT)$msg = "STRICT";
	if($errno == 8192)$msg = "DEPRECATED";
	err("$msg: $errstr in $errfile on line $errline");
}
function err($msg){
	$traces = debug_backtrace();
	if(!$GLOBALS['debug']){
		if(!empty($GLOBALS['err_handler'])){
			call_user_func($GLOBALS['err_handler'], $msg, $traces);
		}else{
			error_log($msg);
		}
	}else{
		if (ob_get_contents()) ob_end_clean();
		function _err_highlight_code($code){
			if(preg_match('/\<\?(php)?[^[:graph:]]/i', $code)){
				return highlight_string($code, TRUE);
			}else{
				return preg_replace('/(&lt;\?php&nbsp;)+/i', "",highlight_string("<?php ".$code, TRUE));
			}
		}
		function _err_getsource($file, $line){
			if(!(file_exists($file) && is_file($file))){
				return '';
			}
			$data = file($file);
			$count = count($data) - 1;
			$start = $line - 5;
			if ($start < 1) {
				$start = 1;
			}
			$end = $line + 5;
			if ($end > $count) {$end = $count + 1;}
			$returns = array();
			for($i = $start; $i <= $end; $i++) {
				if($i == $line){
					$returns[] = "<div id='current'>".$i.".&nbsp;"._err_highlight_code($data[$i - 1], TRUE)."</div>";
				}else{
					$returns[] = $i.".&nbsp;"._err_highlight_code($data[$i - 1], TRUE);
				}
			}
			return $returns;
		}
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta name="robots" content="noindex, nofollow, noarchive" /><meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>
<?php echo $msg;?>
</title>
<style>body{padding:0;margin:0;word-wrap:break-word;word-break:break-all;font-family:Courier,Arial,sans-serif;background:#EBF8FF;color:#5E5E5E;}div,h2,p,span{margin:0; padding:0;}ul{margin:0; padding:0; list-style-type:none;font-size:0;line-height:0;}#body{width:918px;margin:0 auto;}#main{width:918px;margin:13px auto 0 auto;padding:0 0 35px 0;}#contents{width:918px;float:left;margin:13px auto 0 auto;background:#FFF;padding:8px 0 0 9px;}#contents h2{display:block;background:#CFF0F3;font:bold 20px;padding:12px 0 12px 30px;margin:0 10px 22px 1px;}#contents ul{padding:0 0 0 18px;font-size:0;line-height:0;}#contents ul li{display:block;padding:0;color:#8F8F8F;background-color:inherit;font:normal 14px Arial, Helvetica, sans-serif;margin:0;}#contents ul li span{display:block;color:#408BAA;background-color:inherit;font:bold 14px Arial, Helvetica, sans-serif;padding:0 0 10px 0;margin:0;}#oneborder{width:800px;font:normal 14px Arial, Helvetica, sans-serif;border:#EBF3F5 solid 4px;margin:0 30px 20px 30px;padding:10px 20px;line-height:23px;}#oneborder span{padding:0;margin:0;}#oneborder #current{background:#CFF0F3;}</style>
</head>
<body>
<div id="main">
<div id="contents">
<h2><?php echo $msg?></h2>
<?php foreach($traces as $trace)
{
	if(is_array($trace)&&!empty($trace["file"])){
		$souceline = _err_getsource($trace["file"], $trace["line"]);
		if($souceline){
			?><ul><li><span><?php echo $trace["file"];?> on line <?php echo $trace["line"];?> </span></li></ul><div id="oneborder"><?php foreach($souceline as $singleline)echo $singleline;?></div><?php }}}?></div></div><div style="clear:both;padding-bottom:50px;" /></body></html>
			<?php 
		}
	exit;
}
/* End */
