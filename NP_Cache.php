<?php
class NP_Cache extends NucleusPlugin {
	
	var $cache_dir;
	var $cache_path;
	var $skip_cache;
	var $initialized;
	
	public function getName()        { return 'Cache plugin'; }
	public function getVersion()     { return '0.15'; }
	public function getDescription() { return 'The structure like MODX'; }
	public function getURL()         { return 'http://japan.nucleuscms.org/'; }
	public function getAuthor()      { return 'Yamamoto'; }
	
	public function supportsFeature($feature) { return in_array ($feature, array ('SqlTablePrefix', 'SqlApi'));}
	public function getMinNucleusVersion()    { return '350'; }
	
	public function install()   {  $this->purgeCache(); return; }
	public function uninstall() {  $this->purgeCache(); return; }
	
	public function init()
	{
		$this->cache_dir = $this->getDirectory() . 'cache/';
		$this->initialized = 0;
	}
	
	
	public function event_PreLoadMainLibs()
	{
		global $CONF;
		if($CONF['UsingAdminArea']!=0) return;
		$this->initCache();
		$this->initialized = 1;
	}
	
	public function event_PostParseURL(&$data)
	{
		if($this->initialized==0) $this->initCache();
		$this->initialized = 1;
	}
	
	public function event_InitSkinParse(&$data)
	{
		if($this->initialized==0) $this->initCache();
	}
	
	public function initCache() {
		global $member;
		
		if($member->isLoggedIn()) $this->skip_cache = 1;
		if(!empty($_POST))        $this->skip_cache = 1;
		if($this->skip_cache == 1) return;
		$params = include_once($this->cache_dir . 'params_cache.inc.php');
		extract($params);
		if($expire<time()) $this->purgeCache();
		
		$uaType = $this->getUaType();
		$file_name = md5(serverVar('REQUEST_URI') . ":{$uaType}");
		$this->cache_path = "{$this->cache_dir}{$file_name}.inc";
		if(is_file($this->cache_path))
		{
			if(defined('_CHARSET')) $charset = _CHARSET;
			$content = file_get_contents($this->cache_path);
			
			if(strpos($content,md5('<%BenchMark%>'))!==false && function_exists('coreSkinVar')) // For Nucleus v3.70
			{
				$content = str_replace(md5('<%BenchMark%>'),'<%BenchMark%>', $content);
				$rs = coreSkinVar('<%BenchMark%>');
				$content = str_replace('<%BenchMark%>', $rs, $content);
			}
			if(strpos($content,'<%DebugInfo%>')!==false && function_exists('coreSkinVar')) // For Nucleus v3.70
			{
				$rs = coreSkinVar('<%DebugInfo%>');
				$content = str_replace('<%DebugInfo%>', $rs, $content);
			}
			header("Content-Type: text/html; charset={$charset}");
			echo $content;
			exit;
		}
		else ob_start();
	}
	
	public function getUaType()
	{
		$ua = strtolower($_SERVER['HTTP_USER_AGENT']);
		
		if(strpos($ua, 'ipad')!==false)          $type = 'tablet';
		elseif(strpos($ua, 'iphone')!==false)    $type = 'smartphone';
		elseif(strpos($ua, 'ipod')!==false)      $type = 'smartphone';
		elseif(strpos($ua, 'android')!==false)
		{
			if(strpos($ua, 'mobile')!==false)    $type = 'smartphone';
			else                                 $type = 'tablet';
		}
		elseif(strpos($ua, 'windows phone')!==false)
		                                         $type = 'smartphone';
		elseif(strpos($ua, 'docomo')!==false)    $type = 'mobile';
		elseif(strpos($ua, 'softbank')!==false)  $type = 'mobile';
		elseif(strpos($ua, 'up.browser')!==false)
			                                     $type = 'mobile';
		else                                     $type = 'default';
		
		return $type;
	}
	
	public function purgeCache()
	{
		$files = glob("{$this->cache_dir}*.inc");
		$deleted = 0;
		foreach($files as $file)
		{
			unlink($file);
			$deleted++;
		}
		
		$query = sprintf("SELECT * FROM %s WHERE NOW()<itime ORDER BY itime ASC LIMIT 1", sql_table('item'));
		$rs = sql_query($query);
		
		$params = array();
		if(!defined(_CHARSET)) $this->getCHARSET();
		$params['charset'] = _CHARSET;
		
		if(sql_num_rows($rs)==1)
		{
			$row = sql_fetch_assoc($rs);
			$params['expire'] = sprintf('$expire = %s;', strtotime($row['itime']));
		}
		else $params['expire'] = true;
		$content = var_export($params, true);
		file_put_contents($this->cache_dir . 'params_cache.inc.php', "<?php return {$content};");
		
		$this->optimizeTable('actionlog,category,comment,item,skin,template,tickets,plugin_option,plugin_option_desc');
		
		global $admin;
		if($admin->action) $action = " (action:{$admin->action})";
		else               $action = '';
		
		$AddLog = $this->getOption('AddLog');
		if($AddLog==='yes') ACTIONLOG::add(INFO, "Remove cache files({$deleted} files){$action}");
	}
	
	function optimizeTable($table_names)
	{
		$table_names = explode(',', $table_names);
		foreach($table_names as $table_name)
		{
			$table_name = trim($table_name);
			sql_query(sprintf('OPTIMIZE TABLE %s', sql_table($table_name)));
		}
	}
	
	function getCHARSET()
	{
		global $DIR_LANG;
		
		$language = getLanguageName();
		$language = str_replace(array('\\','/'),'',$language);
		include_once("{$DIR_LANG}{$language}.php");
	}
	
	public function event_PostSkinParse(&$data)
	{
		if($this->skip_cache ==1) return;
		
		if(!is_writable($this->cache_dir))
		{
			echo ob_get_clean();
			echo "Can not write cache directory ({$this->cache_dir})";
			return;
		}
		
		$content = ob_get_clean();
		echo $content;
		file_put_contents($this->cache_path,$content);
 	}
	
	public function getEventList()
	{
		$event[] = 'AdminPrePageHead';
		
		$event[] = 'PreLoadMainLibs';
		$event[] = 'PostParseURL';
		$event[] = 'InitSkinParse';
		
		$event[] = 'PostSkinParse';
		
		$event[] = 'PostAddComment';
		$event[] = 'PostDeleteComment';
		
		return $event;
	}
	public function event_AdminPrePageHead($params)
	{
		if(!$params['action']) return;
		
		$this->checkOption($params['action']);
		
		$action[] = 'additem';
		$action[] = 'itemupdate';
		$action[] = 'itemmoveto';
		$action[] = 'categoryupdate';
		$action[] = 'categorydeleteconfirm';
		$action[] = 'itemdeleteconfirm';
		$action[] = 'commentdeleteconfirm';
		$action[] = 'teamdeleteconfirm';
		$action[] = 'memberdeleteconfirm';
		$action[] = 'templatedeleteconfirm';
		$action[] = 'skindeleteconfirm';
		$action[] = 'plugindeleteconfirm';
		$action[] = 'batchitem';
		$action[] = 'batchcomment';
		$action[] = 'batchmember';
		$action[] = 'batchcategory';
		$action[] = 'batchteam';
		$action[] = 'commentupdate';
		$action[] = 'changemembersettings';
		$action[] = 'settingsupdate';
		$action[] = 'blogsettingsupdate';
		$action[] = 'categorynew';
		$action[] = 'memberadd';
		$action[] = 'pluginup';
		$action[] = 'plugindown';
		$action[] = 'pluginupdate';
		$action[] = 'pluginadd';
		$action[] = 'pluginoptionsupdate';
		$action[] = 'skinupdate';
		$action[] = 'skinclone';
		$action[] = 'skineditgeneral';
		$action[] = 'templateclone';
		$action[] = 'templatenew';
		$action[] = 'templateupdate';
		$action[] = 'skinnew';
		$action[] = 'deleteblogconfirm';
		
		if(in_array($params['action'], $action))
			$this->purgeCache();
		
		elseif($params['action']==='plugin_SkinFiles')
		{
			switch($_POST['action'])
			{
				case 'editfile_process':
				case 'delfile_process':
				case 'renfile_process':
					$this->purgeCache();
			}
		}
	}
	public function checkOption($action='')
	{
		if($action!=='pluginlist') return;
		
		$AddLog = $this->getOption('AddLog');
		if(!$AddLog) $this->createOption('AddLog', 'Add logs', 'yesno', 'no');
	}
	
	public function event_PostAddComment(&$data)    { $this->purgeCache(); }
	public function event_PostDeleteComment(&$data) { $this->purgeCache(); }
}
