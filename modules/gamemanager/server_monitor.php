<script type="text/javascript" src="js/modules/gamemanager.js"></script>
<?php
/*
 *
 * OGP - Open Game Panel
 * Copyright (C) 2008 - 2018 The OGP Development Team
 *
 * http://www.opengamepanel.org/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

require_once('modules/gamemanager/home_handling_functions.php');
require_once("modules/config_games/server_config_parser.php");

require_once("includes/refreshed.php");
require_once('includes/lib_remote.php');

function renderParam($param, $last_param, $param_access_enabled, $home_id)
{
	global $db;
	$isAdmin = $db->isAdmin($_SESSION['user_id']);
	$attributesString = "";
	foreach ($param->attribute as $attribute)
		$attributesString .= $attribute['key']. "='$attribute' ";

	$disabledString = ((($param_access_enabled) && (!property_exists($param, 'access') || $param->access != "admin")) || $isAdmin) ? "" : "disabled ";
	
	if (array_key_exists((string)$param['key'], $last_param))
		$paramValue = (string)$last_param[(string)$param['key']];
	else
		$paramValue = (string)$param->default;

	$idString = "id='".clean_id_string($param['key'])."'";
	$nameString = "name='params[".$param['key']."]'";
	$paramType = $param['type'];
	if ($paramType == "select")
	{
		$inputElementString = "<select $idString $nameString $disabledString>";
		foreach ($param->option as $option)
		{
			$optionValue = (string)($option['value']);
			$selectedString = ($optionValue == $paramValue) ? "selected='selected'" : "";
			$valueString = "value=\"".str_replace('"', "&quot;", strip_real_escape_string($optionValue))."\"";
			$inputElementString .= "<option $selectedString $valueString>$option</option>";
		}
		$inputElementString .="</select>";
	}else if($paramType == "other_game_server_path" || $paramType == "other_game_server_path_additional"){
		if($isAdmin){
			$dbTypeHomesStr = "admin";
		}else{
			$dbTypeHomesStr = "user_and_group";
		}
		
		// Get homes
		$homes = $db->getHomesFor($dbTypeHomesStr, $_SESSION['user_id']);
		
		// Move current home_id home_path to the front of the array so that it is selected by default if no other option has been selected.
		$homes = customShift($homes, "home_id", $home_id);
		
		$inputElementString = "<select $idString name='params[" . $param['key'] . "{DEPENDS:other_game_server_path_additional}]'" . $disabledString . ">";
		foreach($homes as $home){
			if($home["home_path"][strlen($home["home_path"])-1] != "/"){
				$home["home_path"] = $home["home_path"] . "/";
			}
			
			if(stripos($paramValue, $home["home_path"]) !== false){
				$selectedString = "selected='selected'";
				$selectedHome = $home["home_path"];
			}else{
				$selectedString = "";
			}
			$inputElementString .= '<option value="' . $home["home_path"] . '" ' . $selectedString . '>' . $home["home_path"] . '</option>';
		}
		$inputElementString .="</select>";
		if($paramType == "other_game_server_path_additional"){
			$inputElementString .="<input type='text' value='" . (stripos($paramValue, $selectedHome) !== false ? substr($paramValue, strlen($selectedHome)) : (hasValue((string)$param->default) ? (string)$param->default : "")) . "' name='params[other_game_server_path_additional]' " . $disabledString .">";
		}
	}else{
			if ($paramType == "checkbox_key_value") {
				if ($paramValue) // convert the XML object to string
					$attributesString .= "checked='checked' ";
				$paramValue = $param['key'];
				$paramType = "checkbox";
			} 
			else if ($paramType == "checkbox")
			{
				if ($paramValue) // convert the XML object to string
					$attributesString .= "checked='checked' ";
			}
			$inputElementString = "<input $idString $nameString ".
				"type='$paramType' value=\"".str_replace('"', "&quot;", strip_real_escape_string($paramValue))."\" ".
				"$disabledString $attributesString/>";
	}

	echo "<tr><td class='right'><label for='".clean_id_string($param['key'])."'>".$param['key'].
		":</label></td><td class='left'>$inputElementString<label for='".clean_id_string($param['key'])."'>";

	if ( !empty($param->caption) )
		echo $param->caption;
	if ( !empty($param->desc) )
		echo "<br/><span class='info'>(".$param->desc.")</span>";

	echo "</label></td></tr>\n";
}

function get_sync_name($server_xml)
{
	if( $server_xml->lgsl_query_name )
	{
		$sync_name = $server_xml->lgsl_query_name;
		if($sync_name == "quake3")
		{
			if($server_xml->game_name == "Quake 3")
				$sync_name = "q3";
		}
	}
	elseif( $server_xml->gameq_query_name )
	{
		$sync_name = $server_xml->gameq_query_name;
		if($sync_name == "minecraft")
		{
			if($server_xml->game_name == "Bukkit")
				$sync_name = "bukkit";
			elseif($server_xml->game_name == "Tekkit")
				$sync_name = "tekkit";
		}
	}
	elseif( isset($server_xml->protocol) )
		$sync_name = $server_xml->protocol;
	else
		$sync_name = $server_xml->mods->mod['key'];
	return $sync_name;
}

function exec_ogp_module() {
	global $db, $settings, $loggedInUserInfo;
	echo "<h2>". get_lang("game_monitor") ."</h2>";
	$refresh = new refreshed();
	set_time_limit(0);
	$stats_servers_online = 0;
	$stats_servers = 0;
	$stats_players = 0;
	$stats_maxplayers = 0;
	
	$home_page = (isset($_GET['page']) && (int)$_GET['page'] > 0) ? (int)$_GET['page'] : 1;
	$home_limit = (isset($_GET['limit']) && (int)$_GET['limit'] > 0) ? (int)$_GET['limit'] : 10;
	$home_cfg_id = (isset($_GET['home_cfg_id']) && (int)$_GET['home_cfg_id'] > 0) ? (int)$_GET['home_cfg_id'] : false;
	
	$search_field = (isset($_GET['search']) && !empty($_GET['search'])) ? $_GET['search'] : false;
	
	
	if(hasValue($loggedInUserInfo) && is_array($loggedInUserInfo) && $loggedInUserInfo["users_page_limit"] && !hasValue($_GET['limit'])){
		$home_limit = $loggedInUserInfo["users_page_limit"];
	}
	
	$isAdmin = $db->isAdmin( $_SESSION['user_id'] );
	
	if ( $isAdmin )
		{
			$show_games_type = $db->getHomesFor('admin', $_SESSION['user_id']);
			if(isset($_GET['home_id']) OR isset($_GET['home_id-mod_id-ip-port']))          
				$server_homes = $db->getHomesFor('admin', $_SESSION['user_id']);
			else
				$server_homes = $db->getHomesFor_limit('admin', $_SESSION['user_id'],$home_page,$home_limit,$home_cfg_id,$search_field);
	
		}
		else
		{
			$show_games_type = $db->getHomesFor('user_and_group', $_SESSION['user_id']);
			if(isset($_GET['home_id']) OR isset($_GET['home_id-mod_id-ip-port']))          
				$server_homes = $db->getHomesFor('user_and_group', $_SESSION['user_id']);
			else			
				$server_homes = $db->getHomesFor_limit('user_and_group', $_SESSION['user_id'],$home_page,$home_limit,$home_cfg_id,$search_field);
		}

	if( $server_homes === FALSE )
	{
		// If there are no games, then there can not be any mods either.

		if (!empty($search_field)) {
			print_failure(get_lang_f('no_results_found', htmlentities($search_field)));
		} else {
			print_failure(get_lang('no_game_homes_assigned'));
		}

		if ( $isAdmin )
		{
			echo "<p><a href='?m=user_games&amp;p=assign&amp;user_id=$_SESSION[user_id]'>".
				 get_lang("assign_game_homes") ."</a></p>";
		}
		return;
	}
	?>
		<form action="home.php" style="float:right;">
			<b><?php print_lang('search'); ?>:</b>
			<input type ="hidden" name="m" value="gamemanager" />
			<input type ="hidden" name="p" value="game_monitor" />
			<input name="search" type="text" id="search" value="<?php if(hasValue($search_field)){ echo $search_field; } ?>" />
			<input type="submit" value="<?php echo get_lang('search'); ?>" />
		</form>
	<?php
	foreach($_POST as $key => $value)
	{
		if( preg_match( "/^action/", $key ) )
		{
			list($action,$home_id,$mod_id,$ip,$port) = explode("-", $value);
			exec_operation( $action, $home_id, $mod_id, $ip, $port );
		}
	}
	
	if ( empty( $_GET['home_id-mod_id-ip-port'] ) )
		unset( $_GET['home_id-mod_id-ip-port'] );
	if ( empty( $_GET['home_id'] ) )
		unset( $_GET['home_id'] );
	if ( isset($_GET['home_cfg_id']) and $_GET['home_cfg_id'] ==  game_type  )
		unset( $_GET['home_cfg_id'] );

	create_home_selector_game_type($_GET['m'], $_GET['p'], $show_games_type);

	if (!isset($_GET['home_id-mod_id-ip-port']) and !isset($_GET['home_id']) and !isset($_GET['home_cfg_id'])) 
	{
		create_home_selector_address($_GET['m'], $_GET['p'], $server_homes);
		$show_all = TRUE;
	}
	else
	{
		create_home_selector_address($_GET['m'], $_GET['p'], $server_homes);
		create_home_selector($_GET['m'], $_GET['p'], "show_all");
		$show_all = FALSE;
	}

	require("protocol/lgsl/lgsl_protocol.php");

	$info = $db->getUserById($_SESSION['user_id']);
	if($info['user_expires'] != "X")
	{
	?>
		<span style="color:black;font-weight:bold;">
			<center>
			<?php echo print_lang('account_expiration'); ?>: <span style="color:green;"><?php echo date( "l, F jS, Y, H:i:s", $info['user_expires'] ).
			" ( ".str_replace('hr', 'hours', read_expire($info['user_expires'])).")"; ?></span>
			</center>
		</span>
	<?php
	}
	
	if($settings["show_server_id_game_monitor"]){
		echo "<p class='serverIdToggle' showtext='" . get_lang('show_server_id') . "' hidetext='" . get_lang('hide_server_id') . "'>" . get_lang('show_server_id') . "</p>";
	}
	
	echo "<table id='servermonitor' class='tablesorter' data-sortlist='[[0,0],[3,1]]'>".
		 "<thead>".
		 "<tr>".
		 "\t<th style='width:16px;background-position: center;'></th>".
		 "\t<th style='width:16px;background-position: center;'></th>".
		 "\t<th class=\"hide serverId\">" . get_lang("server_id") . "</th>".
		 "\t<th>" . get_lang("server_name") . "</th>".
		 "\t<th>" . get_lang("address") . "</th>".
		 "\t<th>" . get_lang("owner") . "</th>".
		 "\t<th class='sorter-false'>".
		 "\t\t" . get_lang("operations") . "".
		 "\t\t<img style='border:0;height:15px;' id='action-stop' src='" . check_theme_image("images/stop.png") . "'/>".
		 "\t\t<img style='border:0;height:15px;' id='action-restart' src='" . check_theme_image("images/restart.png") . "'/>".
		 "\t\t<img style='border:0;height:15px;' id='action-start' src='" . check_theme_image("images/start.png") . "'/>".
		 "\t</th>".
		 "</tr>".
		 "</thead>".
		 "<tbody>";

	$litefm_installed = $db->isModuleInstalled('litefm');
	$ftp_installed = $db->isModuleInstalled('ftp');
	$addonsmanager_installed = $db->isModuleInstalled('addonsmanager');
	$mysql_installed = $db->isModuleInstalled('mysql');
	if( isset( $_GET['home_id-mod_id-ip-port']) )
		list( $post_home_id,
			  $post_mod_id, 
			  $post_ip, 
			  $post_port ) = explode( "-", $_GET['home_id-mod_id-ip-port'] );
	foreach( $server_homes as $server_home )
	{
		if( ( $show_all or isset($_GET['home_cfg_id']) ) AND ( !isset($server_home['ip']) or !isset($server_home['mod_id']) ) )
			continue;
		// Count the number of servers.
		$stats_servers++;
		
		if( $show_all 
			OR ( isset( $_GET['home_id'] ) and $_GET['home_id'] == $server_home['home_id'] ) 
			OR ( isset( $_GET['home_id-mod_id-ip-port'] ) and $server_home['home_id'] == $post_home_id and $server_home['mod_id'] == $post_mod_id and $post_ip == $server_home['ip'] and $post_port == $server_home['port'] ) 
			OR ( isset( $_GET['home_cfg_id'] ) and $_GET['home_cfg_id'] == $server_home['home_cfg_id'] ) 
		  )
		{
			//Unset variables.
			unset($map,
				  $trclass, 
				  $first, 
				  $second, 
				  $onlineT, 
				  $ts3opt, 
				  $offlineT, 
				  $halfT, 
				  $ministart, 
				  $player_list, 
				  $groupsus, 
				  $name,
				  $mod_name,
				  $SrvCtrl,
				  $lite_fm,
				  $manager,
				  $user,
				  $pos,
				  $ftp,
				  $addonsmanager,
				  $ctrlChkBoxes,
				  $expiration_dates);
			
			if ( $isAdmin )
			{
				$server_home['access_rights'] = "ufpetc";
			}
			if ($server_home['mod_name'] == "none" OR $server_home['mod_name'] == "None")
				$mod_name = "";
			elseif($server_home['mod_name'] != $server_home['game_name'])
				$mod_name = " ( ".$server_home['mod_name']." )";
				
			$expiration_dates = "";
			if(isset($server_home['server_expiration_date']) and $server_home['server_expiration_date'] != "X")
				$expiration_dates .= server_expiration_date . ": " . date('d/m/Y H:i:s', $server_home["server_expiration_date"]) . "<br>";
			if(isset($server_home['user_expiration_date']) and $server_home['user_expiration_date'] != "X")
				$expiration_dates .= assign_expiration_date . " (" . user . "): " . date('d/m/Y H:i:s', $server_home["user_expiration_date"]) . "<br>";
			if(isset($server_home['user_group_expiration_date']) and $server_home['user_expiration_date'] != "X")
				$expiration_dates .= assign_expiration_date . " (" . group . "): " . date('d/m/Y H:i:s', $server_home["user_group_expiration_date"]);
			
			$get_size = "<a class='monitorbutton size' data-home_id='".$server_home["home_id"]."'>
				<img src='" . check_theme_image("images/file_size.png") . "' title='". get_lang("get_size") ."'>
				<span>". get_lang("get_size") ."</span>
			</a>";

			$manager = "<a class='monitorbutton' href='?m=user_games&amp;p=edit&amp;home_id=".$server_home['home_id']."'>
				<img src='" . check_theme_image("images/edit.png") . "' title='". get_lang("edit") ."'>
				<span>". get_lang("edit") ."</span>
			</a>";

			// Only show the filemanager link when the litefm is installed.
			if ( preg_match("/f/",$server_home['access_rights']) > 0 && $litefm_installed )
			{
				$lite_fm = "<a class='monitorbutton' href='?m=litefm&amp;home_id=".$server_home['home_id']."'>
					<img src='" . check_theme_image("images/filemanager.png") . "' title='". get_lang("file_manager") ."'>
					<span>". get_lang("file_manager") ."</span>
				</a>";
			}
			
			if ( preg_match("/t/",$server_home['access_rights']) > 0 && $ftp_installed )
			{
				$ftp = "<a class='monitorbutton' href='?m=ftp&amp;home_id=".$server_home['home_id']."'>
                            <img src='" . check_theme_image("images/ftp.png") . "' title='". get_lang("ftp") ."'>
							<span>". get_lang("ftp") ."</span>
						</a>";
			}
			if ( $addonsmanager_installed )
			{
				$addons = $db->resultQuery("SELECT DISTINCT addon_id FROM OGP_DB_PREFIXaddons NATURAL JOIN OGP_DB_PREFIXconfig_homes WHERE home_cfg_id=".$server_home['home_cfg_id']);
				$addons_qty = count($addons);
				if($addons and $addons_qty >= 1){
					$addonsmanager = "<a class='monitorbutton' href='?m=addonsmanager&amp;p=user_addons&amp;home_id=".
                                                                         $server_home['home_id']."&amp;mod_id=".$server_home['mod_id'].
                                                                         "&amp;ip=".$server_home['ip']."&amp;port=".$server_home['port']."'>
                	                        <img src='" . check_theme_image("modules/administration/images/addons_manager.png") . "' title='". get_lang("addons") ."'>
        	                                <span>". get_lang("addons") ." (".$addons_qty.")</span>
	                                </a>";
				}
			}
			
			if ( $mysql_installed )
			{
				$mysql_dbs = $db->resultQuery("SELECT db_id FROM OGP_DB_PREFIXmysql_databases WHERE enabled=1 AND home_id=".$server_home['home_id']);

				if(!empty($mysql_dbs))
					$mysql = "<a class='monitorbutton' href='?m=mysql&p=user_db&home_id=".$server_home['home_id']."'>
                	                        <img src='" . check_theme_image("modules/administration/images/mysql_admin.png") . "' title='". get_lang("mysql_databases") ."'>
        	                                <span>". get_lang("mysql_databases") ."</span>
	                                </a>";
			}

			if( !isset($server_home['mod_id']) )
			{
				$ministart = get_lang("fail_no_mods");

				if ( $isAdmin )
				{
					$ministart .= " <a href='?m=user_games&amp;p=edit&amp;home_id=".$server_home['home_id']."'>" . get_lang("configure_mods") . "</a>";
				}
			}

			$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$server_home['home_cfg_file']);
			
			if ( $server_xml )
			{
				if (preg_match("/u/",$server_home['access_rights']))
				{
					$master_server_home_id = $db->getMasterServer( $server_home['remote_server_id'], $server_home['home_cfg_id'] );
					if ( $master_server_home_id != FALSE )
					{
						if ( !$db->getGameHomeWithoutMods($master_server_home_id) )
						{
							$db->setMasterServer("remove", $master_server_home_id, $server_home['home_cfg_id'], $server_home['remote_server_id']);
							$master_server_home_id = FALSE;
						}
					}
					// In case game is compatible with steam we offer a way to use steam with the updates.
					if( $server_xml->installer == "steamcmd" )
					{						
						if( $master_server_home_id != FALSE AND $master_server_home_id != $server_home['home_id']  )
						{
							$manager .= "<a class='monitorbutton' href='?m=gamemanager&amp;p=update&amp;home_id=".$server_home['home_id']."&amp;mod_id=".$server_home['mod_id']."&amp;master_server_home_id=".$master_server_home_id."&amp;update=update'>
								<img src='" . check_theme_image("images/master.png") . "' title='". get_lang("update_from_local_master_server") ."'>
								<span>". get_lang("update_from_local_master_server") ."</span>
							</a>";
						}

						$manager .= "<a class='monitorbutton' href='?m=gamemanager&amp;p=update&amp;home_id=".$server_home['home_id']."&amp;mod_id=".$server_home['mod_id']."&amp;update=update'>
							<img src='" . check_theme_image("images/steam.png") ."' title='". get_lang("install_update_steam") ."'>
							<span>". get_lang("install_update_steam") ."</span>
						</a>";
						$manager .= "<a class='monitorbutton getAutoUpdateLink' copyfail='" . get_lang("auto_update_copy_me_fail") . "' copysuccess='" . get_lang("auto_update_copy_me_success") . "' autoupdatetext='" . get_lang("auto_update_title_popup") . "' autoupdatehtml='" . htmlentities(get_lang("auto_update_popup_html")) . "' copyme='" . get_lang("auto_update_copy_me") . "' autoupdatelink='" . getOGPSiteURL() . "/ogp_api.php?action=autoUpdateSteamHome&homeid=" . $server_home['home_id'] . "&controlpass=" . $server_home['control_password'] . "'>
							<img src='" . check_theme_image("images/auto_update.png") . "' title='". get_lang("get_steam_autoupdate_api_link") . "'>
							<span>". get_lang("get_steam_autoupdate_api_link") . "</span>
						</a>";
					}
					// In other cases manual update is provided.
					else
					{
						$manager .= "<a class='monitorbutton' href='?m=gamemanager&amp;p=update_manual&amp;home_id=".$server_home['home_id']."&amp;mod_id=".$server_home['mod_id']."&amp;update=update'>
							<img src='" . check_theme_image("images/install.png") . "' title='". get_lang("install_update_manual") ."'>
							<span>". get_lang("install_update_manual") ."</span>
						</a>";


						$sync_name = get_sync_name($server_xml);
						$sync_list = @file("modules/gamemanager/rsync.list", get_lang("FILE_IGNORE_NEW_LINES"));
						if ( in_array($sync_name, $sync_list) OR ($master_server_home_id != FALSE and $master_server_home_id != $server_home['home_id']) )
						{
							$manager .= "<a class='monitorbutton' href='?m=gamemanager&amp;p=rsync_install&amp;home_id=".$server_home['home_id']."&amp;mod_id=".$server_home['mod_id']."&amp;update=update'>
								<img src='" . check_theme_image("images/rsync.png") . "' title='". rsync_install ."'>
								<span>". get_lang("rsync_install") ."</span>
							</a>";

						}
					}
				}

				if ($db->isModuleInstalled("editconfigfiles") && !empty($server_xml->configuration_files)) {
					$manager .= "<a href=\"?m=editconfigfiles&home_id=".(int)$server_home['home_id']."\" class=\"monitorbutton\">
									<img src='" . check_theme_image("images/editconfig.png") . "' title='". get_lang("edit_configuration_files") ."'>
									<span>". get_lang("edit_configuration_files") ."</span>
					</a>";
				}

				if (preg_match("/c/",$server_home['access_rights'])){
					if( isset($server_xml->custom_fields) ) {
						$manager .= "<a href=\"?m=user_games&p=custom_fields&home_id=".$server_home['home_id']."\" class=\"monitorbutton\">
										<img src='" . check_theme_image("images/customfields.png") . "' title='". get_lang("custom_fields") ."'>
										<span>". get_lang("custom_fields") ."</span>
									</a>";
					}
				}

			}

			if( $isAdmin )
			{
				if ( ( $server_xml->control_protocol and preg_match("/^(rcon|lcon|rcon2|armabe)$/" ,$server_xml->control_protocol) ) OR 
					 ( $server_xml->gameq_query_name and $server_xml->gameq_query_name == 'minecraft' ) )
				{
					$manager .= "<a class='monitorbutton' href='home.php?m=gamemanager&amp;p=rcon_presets&amp;home_id=".$server_home['home_id']."&amp;mod_id=".$server_home['mod_id']."'>
						<img src='" . check_theme_image("images/rcon_preset.png") . "' title='".get_lang("rcon_presets")."'>
						<span>".get_lang("rcon_presets")."</span>
					</a>";

				}
			}
			
			$mod = $server_home['mod_key'];
			// If query name does not exist use mod key instead.
			if ($server_xml->protocol == "gameq")
				$query_name = $server_xml->gameq_query_name;
			elseif ($server_xml->protocol == "lgsl")
				$query_name = $server_xml->lgsl_query_name;
			elseif ($server_xml->protocol == "teamspeak3")
				$query_name = 'ts3';
			else
				$query_name = $mod;
			
			//----------+ getting the lgsl image icon
			$icon_paths = array("images/icons/$mod.png",
								"images/icons/$query_name.png",
								"protocol/lgsl/other/icon_unknown.gif");

			$icon_path = get_first_existing_file($icon_paths);

			//Properties for all servers
			if(isset($post_home_id) && $post_home_id == $server_home['home_id'] OR isset($_GET['home_id']) && $_GET['home_id'] == $server_home['home_id'] )
				$trclass = " expandme";

			$groupusers = $db->getGroupUsersByHomeId($server_home['home_id']);
			$groupsus = "";
			if($groupusers)
			{
				foreach($groupusers as $groupu)
				{
					if($groupu['user_id'] == $server_home['user_id_main'])
						continue;
					$groupsus .= $groupu['users_login']."<br>";
				}
			}
			$groupsus = $groupsus != "" ? $groupsus = "<b>". get_lang("group_users") ."</b><br>".$groupsus : "";

			$owners = $db->getUsersByHomeId($server_home['home_id']);
			$other_owners = "";
			if($owners)
			{
				foreach($owners as $owner)
				{
					if($owner['user_id'] == $server_home['user_id_main'])
						continue;
					$other_owners .= $owner['users_login'].'<br>';
				}
			}
			$other_owners = $other_owners != "" ? $other_owners = "<b>". get_lang("assigned_to") ."</b><br>".$other_owners : "";

			$view_log = "<a class='monitorbutton' href='?m=gamemanager&amp;p=log&amp;home_id-mod_id-ip-port=".$server_home['home_id']."-".$server_home['mod_id']."-".$server_home['ip']."-".$server_home['port']."'>
				<img src='" . check_theme_image("images/log.png") . "' title='". get_lang("view_log") ."'>
				<span>". get_lang("view_log") ."</span>
			</a>";


			$btns = $view_log.
					@$ftp.
					@$lite_fm.
					@$addonsmanager;
			//End

			$remote = new OGPRemoteLibrary($server_home['agent_ip'], $server_home['agent_port'], $server_home['encryption_key'], $server_home['timeout']);
			$host_stat = $remote->status_chk();

			if( $host_stat === 1)
			{
				if($server_home['use_nat'] == 1 ){
					$ip = $server_home['agent_ip'];
				}else{
					$ip = $server_home['ip'] != $server_home['agent_ip'] ? $server_home['ip'] : $server_home['agent_ip'];
				}
				$query_ip = checkDisplayPublicIP($server_home['display_public_ip'],$ip);
				$address = $query_ip . ":" . $server_home['port'];

				$screen_running = $remote->is_screen_running(OGP_SCREEN_TYPE_HOME,$server_home['home_id']) === 1;
				$update_in_progress = $remote->is_screen_running(OGP_SCREEN_TYPE_UPDATE,$server_home['home_id']) === 1;
				if($screen_running)
				{
					// Check if the screen running the server is running.
					$status = "online";
					$order=1;
					if ($server_xml->protocol == "lgsl")
					{
						$get_q_and_s = lgsl_port_conversion($query_name, $server_home['port'], "", "");
						//Connection port
						$c_port = $get_q_and_s['0'];
						//query port
						$q_port = $get_q_and_s['1'];
						//software port
						$s_port = $get_q_and_s['2'];
						$address = "<a href='" . lgsl_software_link($query_name, $query_ip, $c_port, $q_port, $s_port) . "'>".$query_ip.":".$server_home['port']."</a>";
					}
					if ($server_xml->protocol == "teamspeak3")
						$address = "<a href='ts3server://" . $query_ip . ":" . $server_home['port'] . "'>".$query_ip.":".$server_home['port']."</a>";
					if($server_xml->protocol == "gameq" and $server_xml->installer == 'steamcmd')
						$address = "<a href='steam://connect/" . $query_ip . ":" . $server_home['port'] . "'>" . $query_ip . ":" . $server_home['port'] . "</a>";
					$pos = $refresh->add("home.php?m=gamemanager&p=ref_servermonitor&type=cleared&home_id=". $server_home['home_id'] . "&mod_id=". $server_home['mod_id'] . "&ip=" . $server_home['ip'] . "&port=" . $server_home['port']);
					if ($server_xml->protocol == "teamspeak3")
					{
						require('protocol/TeamSpeak3/functions.php');
					}
					$startup_file_exists = $remote->rfile_exists( "startups/".$server_home['ip']."-".$server_home['port'] ) === 1;
					if( isset($server_home['ip']) and isset($server_home['mod_id']) and ($server_xml->protocol != "teamspeak3" or ($startup_file_exists and $server_xml->protocol == "teamspeak3")) )
					{
						$ctrlChkBoxes .= '<div id="server_icon" class="action-stop'.$server_home['home_id'].'" ><div>'.
										 '<input id="action-stop" class="action-stop'.$server_home['home_id'].'" name="action-'.$server_home['home_id'].'" value="stop-'.
										 $server_home['home_id'].'-'.$server_home['mod_id'].'-'.$server_home['ip'].'-'.$server_home['port'].
										 '" type="radio"><img style="border:0;height:15px;" src="'  . check_theme_image("images/stop.png") . '"/></div><div>&nbsp;'.
										  get_lang("stop_server") .'</div></div><div id="server_icon" class="action-restart'.$server_home['home_id'].'" ><div>'.
										 '<input id="action-restart" class="action-restart'.$server_home['home_id'].'" name="action-'.$server_home['home_id'].'" value="restart-'.
										 $server_home['home_id'].'-'.$server_home['mod_id'].'-'.$server_home['ip'].'-'.$server_home['port'].
										 '" type="radio"><img style="border:0;height:15px;" src="' . check_theme_image("images/restart.png") . '"/></div><div>&nbsp;'.
										  get_lang("restart_server") .'</div></div>';
					}
					$stats_servers_online++;
				}
				else
				{
					$status = "offline";
					if ($server_home['last_param'] != "" and isset($server_home['ip']) and isset($server_home['mod_id']))
					{
						if($update_in_progress)
							$ctrlChkBoxes .= '<div id="server_icon" class="action-start'.$server_home['home_id'].'" >&nbsp;'. get_lang("update_in_progress") .'</div>';
						else
							$ctrlChkBoxes .= '<div id="server_icon" class="action-start'.$server_home['home_id'].'" >
											 <div>
											 <input id="action-start" class="action-start'.$server_home['home_id'].'" name="action-'.$server_home['home_id'].'" value="start-'.
											 $server_home['home_id'].'-'.$server_home['mod_id'].'-'.$server_home['ip'].'-'.$server_home['port'].
											 '" type="radio"><img style="border:0;height:15px;" src="' . check_theme_image("images/start.png") . '"/></div><div>&nbsp;'.
											  get_lang("start_server") .'</div></div>';
					}
					$order = 3;
					if(isset($server_home['mod_id']))
					{
						ob_start();
						require('modules/gamemanager/mini_start.php');
						$ministart = ob_get_contents();
						ob_end_clean();
					}
					if($update_in_progress)
						$offlineT = '<div id="server_icon" class="action-start'.$server_home['home_id'].'" >&nbsp;'. get_lang("update_in_progress") .'</div>';
					else
						$offlineT = $ministart;
				}
			}
			else{
				$status = "offline";
				$order = 3;
				$address = "<span style='color:darkred;font-weight:bold;'>Agent Offline</span>";
			}
			$user = $db->getUserById($server_home['user_id_main']);

			// Template
			@$first = "<tr class='maintr$trclass'>";
				$first .= "<td class='collapsible' data-status='$status' data-pos='$pos'><span class='hidden'>$order</span>" . "<img src='" . check_theme_image("images/$status.png") . "' />" . "</td>";
				$first .= "<td class='collapsible'>" . "<span class='hidden'>$mod</span><img src='$icon_path' />" . "</td>";
				$first .= "<td class='collapsible serverId hide'>" . $server_home["home_id"] . "</td>";
				$first .= "<td class='collapsible' data-status='$status' data-pos='$pos'><b>" . htmlentities($server_home['home_name']) . "</b>$mod_name</td>";
				$first .= "<td class='collapsible'>" . $address . "</td>";
				$first .= "<td class='owner collapsible'>" . $user['users_login'] . "</td>";
				$first .= "<td style='width:328px;padding:0px;'>$ctrlChkBoxes</td>";
			$first .= "</tr>";

			$second = "<tr class='expand-child'>";
				@$second .= "<td colspan='4'>" . $refresh->getdiv($pos,"width:100%;") . "$offlineT</td>";
				$second .= "<td class='owner' >$other_owners$groupsus</td>";
				if( $server_xml->protocol != "teamspeak3" OR ($startup_file_exists and $server_xml->protocol == "teamspeak3") OR ($status == "offline" and $server_xml->protocol == "teamspeak3") )
					@$second .= "<td class='operations'><div class='inline-block monitorButtonContainer'>" . trim($btns) . trim($manager) . trim($mysql) . trim($get_size) . trim($ts3opt) . "<b class='failure' style='float:left;' >$expiration_dates</b></div></td>";
				else
					$second .= "<td class='operations' >$ts3opt</td>";
			$second .= "</tr>";
			//Echo them all
			echo "$first$second";
		}
	}
	echo "</tbody>";

	echo "<tfoot style='border:1px solid grey;'>
			<tr>
			  <td colspan='7' >
				<div class='bloc' >
				<img src='" . check_theme_image("images/magnifglass.png") . "' /> ". get_lang("statistics") .": $stats_servers_online/$stats_servers ". get_lang("servers") ."\n</div>
				<div class='right bloc' >
				  <label>". get_lang("execute_selected_server_operations") ."</label>
				  <input id='execute_operations' type='submit' value='". get_lang("execute_operations") ."' >\n
				</div>
			  </td>
			</tr>
		  </tfoot>";

	echo "</table>";

	if ($isAdmin) {	
		$homes_count = $db->getHomesFor_count('admin', $_SESSION['user_id'], $home_cfg_id,$search_field);
	} else {
		$isSubUser = $db->isSubUser($_SESSION['user_id']);

		if ($isSubUser) {
			$homes_count = $db->getHomesFor_count('subuser',$_SESSION['user_id'], $home_cfg_id,$search_field);
		} else {
			$homes_count = $db->getHomesFor_count('user_and_group',$_SESSION['user_id'], $home_cfg_id,$search_field);
		}	
	}


	if(isset($_GET['home_cfg_id']) && !empty($_GET['home_cfg_id'])){
	$uri = '?m=gamemanager&p=game_monitor&home_cfg_id='.$_GET['home_cfg_id'].''.($search_field ? "&search=$search_field" : "").'&limit='.$home_limit.'&page=';
	}
	else{
	$uri = '?m=gamemanager&p=game_monitor'.($search_field ? "&search=$search_field" : "").'&limit='.$home_limit.'&page=';	
	}
	
	if(!isset($_GET['home_id-mod_id-ip-port']) && !isset($_GET['home_id']))
	{echo paginationPages($homes_count[0]['total'], $home_page, $home_limit, $uri, 3, 'serverMonitor');}

	echo "<div id=translation data-title='". get_lang("upload_map_image") .
		 "' data-upload_button='". get_lang("upload_image") .
		 "' data-bad_file='". get_lang("jpg_gif_png_less_than_1mb") .
		 "' data-upload_failure='". get_lang("check_dev_console") .
		 "' ></div>\n";
	?>
	<script type="text/javascript">
	<?php echo $refresh->build(isset($settings['query_cache_life']) ? $settings['query_cache_life'] * 2000 : 60000); ?>
	</script>
	<?php
}
?>
