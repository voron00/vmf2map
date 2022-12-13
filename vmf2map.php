<?php
if (!isset($argv[1]))
	die("ERROR: No map specified, aborting.\n");

ini_set("memory_limit", "512M");

$name = $argv[1];

if (file_exists($name))
	echo "Converting $name\n";

else
	die("ERROR: File not found, aborting.\n");

$vmfString = file_get_contents($name);

if (!preg_match('/^(world|versioninfo)\b/', $vmfString))
	die("ERROR: Invalid file loaded, aborting.\n");

$vmf = parseVMF($vmfString);
$map = exportVMFasMAP($vmf);
$gsc = exportVMFasGSC($vmf);

$newmap = str_replace(".vmf", ".map", $name);
$newgsc = str_replace(".vmf", ".gsc", $name);

file_put_contents($newmap, $map);
echo("Writing $newmap\n");

file_put_contents($newgsc, $gsc);
echo("Writing $newgsc\n");

die("SUCCESS: Converting completed.\n");

function parseVMF($vmfString)
{
	$debug = false;

	$len = strlen($vmfString);

	if ($debug)
		$len = 2098;

	$stack = array();

	$isInQuote = false;
	$key = "";
	$value = "";
	$quoteKey = "";
	$quoteValue = "";
	$quoteWhat = "key";

	for ($i = 0; $i < $len; $i++)
	{
		$c = $vmfString[$i]; // current char

		switch ($c)
		{
		case "\r":
			break;

		case "\n":
			if (strlen(trim($key)))
			{
				$stack[] = $key;
				$key = "";
			}
			break;

		case "\"":

			if ($isInQuote) // so we are CLOSING key or value
			{
				if (strlen($quoteKey) && strlen($quoteValue))
				{
					$stack[] = array($quoteKey=>$quoteValue);
					$quoteKey = "";
					$quoteValue = "";
				}

				if ($quoteWhat == "key")
					$quoteWhat = "value";
				else if ($quoteWhat == "value")
					$quoteWhat = "key";
			}

			$isInQuote = !$isInQuote;
			break;

		case "{":
			$stack[] = "ENTER-GROUP";
			if ($debug) echo "{";
			break;

		case "}":
			$stack[] = "LEAVE-GROUP";
			if ($debug) echo "}";
			break;

		case "\t":
			break;

		default:
			if (!$isInQuote && strlen(trim($c)))
				$key .= $c;

			if ($isInQuote)
				if ($quoteWhat == "key")
					$quoteKey .= $c;
				else
					$quoteValue .= $c;
		}
	}

	if ($debug)
		echo "<hr>";

	$vmf = array();

	$depth = 0;
	$parent = &$vmf;
	$history = array();
	$groupname = "not init";

	foreach ($stack as $element)
	{
		if (!is_array($element)) // is groupname or GROUP-indicator
		{
			if ($element == "ENTER-GROUP")
			{
				if ($debug) echo "ENTER GROUP depth=$depth\n";
				continue;
			}

			if ($element == "LEAVE-GROUP")
			{
				if ($debug) echo "LEAVE GROUP\n";

				$depth--;
				$parent = &$history[$depth];
				continue;
			}

			$groupname = $element;

			if (!isset($parent[$groupname]))
				$parent[$groupname] = array();

			$history[$depth] = &$parent;
			$parent = &$parent[$groupname][];
			$depth++;

			if ($debug)
				echo "NEW GROUP $groupname\n";
		}
		else
		{
			$keys = array_keys($element);
			$key = $keys[0];
			$val = $element[$key];

			if ($debug)
				$n = count($parent);

			$parent[$key] = $val;
		}
	}

	if ($debug)
		echo "<hr>";

	if ($debug)
		var_dump($vmf);

	return $vmf;
}

function fixWaterBrush(&$solid)
{
	$isOnlyWaterAndNodraw = true;
	$watername = "";

	foreach ($solid["side"] as $side)
		if (strpos(strtoupper($side["material"]), "WATER")!==false || strpos(strtoupper($side["material"]), "TOOLSNODRAW")!==false)
		{
			if (strpos(strtoupper($side["material"]), "WATER")!==false)
				$watername = $side["material"];
		}
		else
			return false;

	if (trim($watername) == "") // its completely nodraw
		return false;

	foreach ($solid["side"] as &$side)
		$side["material"] = $watername;

	return true;
}

function exportVMFasGSC($vmf)
{
	$gsc = "main()\n{\n\tmaps\mp\_load::main();\n\tstd\item::precache();\n\n";

	foreach ($vmf["entity"] as $entity)
	{
		if (
		    $entity["classname"] != "item_ammopack_medium"
		    && $entity["classname"] != "item_healthkit_medium"
		    && $entity["classname"] != "item_ammopack_small"
		    && $entity["classname"] != "item_healthkit_small"
		)
			continue;

		$classname = $entity["classname"];
		$origin = str_replace(" ", ",", $entity["origin"]);
		$angles = str_replace(" ", ",", $entity["angles"]);
		$gsc .= "\tstd\item::addItem(\"$classname\", ($origin), ($angles));\n";
	}

	$gsc .= "}";

	return $gsc;
}

function exportVMFasMAP($vmf)
{
	$map = "";
	$map .= "iwmap 4\n";

	// PRINT NORMAL BRUSHES
	$map .= "{\n";
	$map .= "\t\"classname\" \"worldspawn\"\n";

	// global light values
	$map .= "\t\"ambient\" \".5\"\n";
	$map .= "\t\"sundirection\" \"-60 250 0\"\n";
	$map .= "\t\"_color\" \"1 1 1\"\n";
	$map .= "\t\"suncolor\" \"1.000000 1.000000 0.882353\"\n";
	$map .= "\t\"sundiffusecolor\" \"0.909804 0.952941 1.000000\"\n";
	$map .= "\t\"sunlight\" \"2\"\n";

	// some brushes are in world
	foreach ($vmf["world"][0]["solid"] as $solid)
	{
		if (fixWaterBrush($solid) && 0)
			echo "Fixed Water on Brush Solid-ID=" . $solid["id"] . "\n";

		$map .= "\t{\n";

		foreach ($solid["side"] as $side)
		{
			$map .= "\t\t" . $side["plane"] . " " .getNewMaterial($side["material"]). " 64 64 0 0 0 0 lightmap_gray 16384 16384 0 0 0 0 \n";
		}

		$map .= "\t}\n";
	}

	// others are func_-entities
	foreach ($vmf["entity"] as $entity)
	{
		if (
		    $entity["classname"] != "func_detail"
		    && $entity["classname"] != "func_brush"
		    && $entity["classname"] != "func_illusionary"
		    && $entity["classname"] != "func_areaportal"
		    && $entity["classname"] != "func_areaportalwindow"
		    && $entity["classname"] != "func_occluder"
		    && $entity["classname"] != "func_tracktrain"
		    && $entity["classname"] != "func_door"
		    && $entity["classname"] != "func_door_rotating"
		    && $entity["classname"] != "func_rotating"
		    && $entity["classname"] != "func_useableladder"
		    && $entity["classname"] != "func_ladderendpoint"
		    && $entity["classname"] != "func_button"
		)
			continue;

		// if illusionary: add clip_player PLUS normal textures as non-colliding.
		// so ppl can shoot through but cant go through.

		if ($entity["classname"] == "func_illusionary")
		{
			foreach ($entity["solid"] as $solid)
			{
				$map .= "\t// func_illusionary\n";
				$map .= "\t{\n";

				foreach ($solid["side"] as $side)
				{
					$map .= "\t\t" . $side["plane"] . " clip 64 64 -88 8 0 0 lightmap_gray 16384 16384 0 0 0 0\n";
				}

				$map .= "\t}\n";
			}
		}

		foreach ($entity["solid"] as $solid)
		{
			if (fixWaterBrush($solid) && 0)
				echo "Fixed Water on Brush Entity-ID=" . $entity["id"] . "\n";

			$map .= "\t{\n";

			if ($entity["classname"] == "func_illusionary")
			{
				$map .= "\tcontents nonColliding;\n";
			}

			foreach ($solid["side"] as $side)
			{
				$map .= "\t\t" . $side["plane"] . " " .getNewMaterial($side["material"]). " 64 64 0 0 0 0 lightmap_gray 16384 16384 0 0 0 0 \n";
			}

			$map .= "\t}\n";
		}

		// func_detail brushes
		if ($entity["classname"] == "func_detail")
		{
			foreach ($entity["solid"] as $solid)
			{
				$map .= "\t{\n";
				$map .= "\tcontents detail;\n";

				foreach ($solid["side"] as $side)
				{
					$map .= "\t\t" . $side["plane"] . " " .getNewMaterial($side["material"]). " 64 64 0 0 0 0 lightmap_gray 16384 16384 0 0 0 0 \n";
				}

				$map .= "\t}\n";
			}
		}
	}

	$map .= "}\n\n"; // all brushes are in worldspawn, now parse the entities

	// spawnpoints
	foreach ($vmf["entity"] as $entity)
	{
		if ($entity["classname"] != "info_player_teamspawn")
			continue;

		if (!isset($entity["TeamNum"]))
			continue;

		if ($entity["TeamNum"] == "2")
			$classname = "mp_ctf_spawn_allied";
		else
			$classname = "mp_ctf_spawn_axis";

		$origin = $entity["origin"];
		$angles = $entity["angles"];

		$map .= "{\n";
		$map .= "\t\"origin\" \"$origin\"\n";
		$map .= "\t\"classname\" \"$classname\"\n";
		$map .= "\t\"angles\" \"$angles\"\n";
		$map .= "}\n";

		// global intermission
		$pieces = explode(" ", $origin);

		$x = $pieces[0];
		$y = $pieces[1];
		$z = $pieces[2]+88;

		$map .= "{\n";
		$map .= "\t\"classname\" \"mp_global_intermission\"\n";
		$map .= "\t\"origin\" \"$x $y $z\"\n";
		$map .= "\t\"angles\" \"$angles\"\n";
		$map .= "}\n";
	}

	foreach ($vmf["entity"] as $entity)
	{
		if ($entity["classname"] != "info_player_counterterrorist")
			continue;

		$origin = $entity["origin"];
		$angles = $entity["angles"];

		$map .= "{\n";
		$map .= "\t\"origin\" \"$origin\"\n";
		$map .= "\t\"classname\" \"mp_ctf_spawn_allied\"\n";
		$map .= "\t\"angles\" \"$angles\"\n";
		$map .= "}\n";

		// global intermission
		$pieces = explode(" ", $origin);

		$x = $pieces[0];
		$y = $pieces[1];
		$z = $pieces[2]+88;

		$map .= "{\n";
		$map .= "\t\"classname\" \"mp_global_intermission\"\n";
		$map .= "\t\"origin\" \"$x $y $z\"\n";
		$map .= "\t\"angles\" \"$angles\"\n";
		$map .= "}\n";
	}

	foreach ($vmf["entity"] as $entity)
	{
		if ($entity["classname"] != "info_player_terrorist")
			continue;

		$origin = $entity["origin"];
		$angles = $entity["angles"];

		$map .= "{\n";
		$map .= "\t\"origin\" \"$origin\"\n";
		$map .= "\t\"classname\" \"mp_ctf_spawn_axis\"\n";
		$map .= "\t\"angles\" \"$angles\"\n";
		$map .= "}\n";

		// global intermission
		$pieces = explode(" ", $origin);

		$x = $pieces[0];
		$y = $pieces[1];
		$z = $pieces[2]+88;

		$map .= "{\n";
		$map .= "\t\"classname\" \"mp_global_intermission\"\n";
		$map .= "\t\"origin\" \"$x $y $z\"\n";
		$map .= "\t\"angles\" \"$angles\"\n";
		$map .= "}\n";
	}

	// triggers
	foreach ($vmf["entity"] as $entity)
	{
		if (
		    $entity["classname"] != "trigger_teleport"
		    && $entity["classname"] != "trigger_push"
		)
			continue;

		foreach ($entity["solid"] as $solid)
		{
			$map .= "{\n";
			$map .= "\t\"classname\" \"trigger_multiple\"\n";
			$map .= "\t\"targetname\" \"{$entity["classname"]}\"\n";

			if (isset($entity["target"]))
				$map .= "\t\"target\" \"{$entity["target"]}\"\n";

			$count = 0;

			if (isset($entity["speed"]))
			{
				$map .= "\t\"speed\" \"{$entity["speed"]}\"\n";
				$count++;
			}

			if (isset($entity["pushdir"]))
			{
				$map .= "\t\"pushdir\" \"{$entity["pushdir"]}\"\n";
				$count++;
			}

			if ($count == 2) // hack... add later support for other key-values inside server
				$map .= "\t\"target\" \"{$entity["speed"]}|{$entity["pushdir"]}\"\n";

			if (isset($entity["TeamNum"]))
			{
				$map .= "\t\"target\" \"{$entity["TeamNum"]}\"\n";
				$count++;
			}

			$map .= "\t{\n";

			foreach ($solid["side"] as $side)
			{
				$map .= "\t\t" . $side["plane"] . " " .getNewMaterial($side["material"]). " 64 64 0 0 0 0 lightmap_gray 16384 16384 0 0 0 0 \n";
			}

			$map .= "\t}\n";
			$map .= "}\n";
		}
	}

	// trigger_hurt
	foreach ($vmf["entity"] as $entity)
	{
		if ($entity["classname"] != "trigger_hurt")
			continue;

		$damage = $entity["damage"];

		foreach ($entity["solid"] as $solid)
		{
			$map .= "{\n";
			$map .= "\t\"dmg\" \"$damage\"\n";
			$map .= "\t\"classname\" \"trigger_hurt\"\n";

			$map .= "\t{\n";
			foreach ($solid["side"] as $side)
			{
				$map .= "\t\t" . $side["plane"] . " " .getNewMaterial($side["material"]). " 64 64 0 0 0 0 lightmap_gray 16384 16384 0 0 0 0 \n";
			}

			$map .= "\t}\n";
			$map .= "}\n";
		}
	}

	// origins
	foreach ($vmf["entity"] as $entity)
	{
		if ($entity["classname"] != "info_teleport_destination")
			continue;

		$origin = $entity["origin"];
		$targetname = $entity["targetname"];

		$map .= "{\n";
		$map .= "\t\"classname\" \"script_origin\"\n";
		$map .= "\t\"origin\" \"$origin\"\n";
		$map .= "\t\"angles\" \"" . $entity["angles"] . "\"\n";
		$map .= "\t\"targetname\" \"$targetname\"\n";
		$map .= "}\n";
	}

	// lights
	foreach ($vmf["entity"] as $entity)
	{
		if ($entity["classname"] != "light")
			continue;

		$origin = $entity["origin"];

		if (isset($entity["_zero_percent_distance"]))
			$radius = $entity["_zero_percent_distance"];
		else
			$radius = 250;

		if (!$radius)
			$radius = $entity["_fifty_percent_distance"]*2;

		if (!$radius)
			$radius = 250;

		if ($entity["_light"])
		{
			$pieces = explode(" ", $entity["_light"]);
			$r = $pieces[0]/255;
			$g = $pieces[1]/255;
			$b = $pieces[2]/255;
			$color = "$r $g $b";
		}
		else
			$color = "1 1 1";

		$map .= "{\n";
		$map .= "\t\"origin\" \"$origin\"\n";
		$map .= "\t\"_color\" \"$color\"\n";
		$map .= "\t\"radius\" \"$radius\"\n";
		$map .= "\t\"classname\" \"light\"\n";
		$map .= "}\n";
	}

	// light_spot
	foreach ($vmf["entity"] as $entity)
	{
		if ($entity["classname"] != "light_spot")
			continue;

		$origin = $entity["origin"];
		$pieces = explode(" ", $origin);

		$x = $pieces[0];
		$y = $pieces[1];
		$z = $pieces[2]-8;

		$origin = "$x $y $z";

		if (isset($entity["_distance"]))
			$radius = $entity["_distance"];
		else
			$radius = 250;

		if (!$radius)
			$radius = 250;

		if ($entity["_light"])
		{
			$pieces = explode(" ", $entity["_light"]);
			$r = $pieces[0]/255;
			$g = $pieces[1]/255;
			$b = $pieces[2]/255;
			$color = "$r $g $b";
		}
		else
			$color = "1 1 1";

		$map .= "{\n";
		$map .= "\t\"origin\" \"$origin\"\n";
		$map .= "\t\"_color\" \"$color\"\n";
		$map .= "\t\"radius\" \"$radius\"\n";
		$map .= "\t\"classname\" \"light\"\n";
		$map .= "}\n";
	}

	// weapons
	foreach ($vmf["entity"] as $entity)
	{
		if (!preg_match("/^weapon_(ak47|aug|awp|deagle|elite|famas|fiveseven|g3sg1|galil|glock|hegrenade|m249|m3|m4a1|mac10|mp5navy|p228|p90|scout|sg550|sg552|smokegrenade|tmp|ump45|usp|xm1014)$/i", $entity["classname"]))
			continue;

		$origin = $entity["origin"];
		$weapon = getNewWeapon($entity["classname"]);
		$map .= "{\n";
		$map .= "\t\"origin\" \"$origin\"\n";
		$map .= "\t\"classname\" \"$weapon\"\n";
		$map .= "}\n";
	}

	// models
	foreach($vmf['entity'] as $entity)
	{
		if (!isset($entity["model"]))
			continue;

		if (
		    $entity["model"] != "models/egypt/palm_tree/palm_tree_medium.mdl"
		    && $entity["model"] != "models/props_spytech/work_table001.mdl"
		    && $entity["model"] != "models/props_spytech/terminal_chair.mdl"
		    && $entity["model"] != "models/props_2fort/miningcrate001.mdl"
		    && $entity["model"] != "models/props_2fort/miningcrate002.mdl"
		    && $entity["model"] != "models/props_gameplay/haybale.mdl"
		    && $entity["model"] != "models/props_farm/wooden_barrel.mdl"
		    && $entity["model"] != "models/props_vehicles/pickup03.mdl"
		    && $entity["model"] != "models/harvest/tree/tree_small.mdl"
		    && $entity["model"] != "models/harvest/tree/tree_medium.mdl"
		    && $entity["model"] != "models/harvest/tree/tree_big.mdl"
		    && $entity["model"] != "models/props_medieval/sconce.mdl"
		    && $entity["model"] != "models/props_trainyard/distillery_barrel001.mdl"
		)
			continue;

		$map .= "{\n";
		$map .= "\t\"classname\" \"misc_model\"\n";
		$map .= "\t\"model\" \"" . getNewModel($entity["model"]) . "\"\n";
		$map .= "\t\"origin\" \"" . fixModelOrigin($entity["model"], $entity["origin"]) . "\"\n";
		$map .= "\t\"angles\" \"" . fixModelAngles($entity["model"], $entity["angles"]) . "\"\n";
		$map .= "}\n";
	}

	return $map;
}

function fixModelOrigin($model, $origin)
{
	$pieces = explode(" ", $origin);

	$x = $pieces[0];
	$y = $pieces[1];
	$z = $pieces[2];

	switch($model)
	{
	case "models/props_farm/wooden_barrel.mdl":
		$z = $z-30;
		break;

	case "models/props_trainyard/distillery_barrel001.mdl":
		$z = $z-30;
		break;

	default:
		break;
	}

	$origin = "$x $y $z";

	return $origin;
}

function fixModelAngles($model, $angles)
{
	$pieces = explode(" ", $angles);

	$x = $pieces[0];
	$y = $pieces[1];
	$z = $pieces[2];

	switch($model)
	{
	case "models/props_spytech/work_table001.mdl":
		$y = $y-90;
		break;

	case "models/props_medieval/sconce.mdl":
		$y = $y+90;
		break;

	default:
		break;
	}

	$angles = "$x $y $z";

	return $angles;
}

function getNewModel($modelname)
{
	switch($modelname)
	{
	case "models/egypt/palm_tree/palm_tree_medium.mdl":
		$model = "xmodel/tree_desertpalm01";
		break;

	case "models/props_spytech/work_table001.mdl":
		$model = "xmodel/furniture_longdiningtable";
		break;

	case "models/props_spytech/terminal_chair.mdl":
		$model = "xmodel/furniture_plainchair";
		break;

	case "models/props_2fort/miningcrate001.mdl":
		$model = "xmodel/crate01";
		break;

	case "models/props_2fort/miningcrate002.mdl":
		$model = "xmodel/prop_crate_dak8";
		break;

	case "models/props_gameplay/haybale.mdl":
		$model = "xmodel/prop_haybale";
		break;

	case "models/harvest/tree/tree_big.mdl":
		$model = "xmodel/tree_destoyed_trunk_b";
		break;

	case "models/harvest/tree/tree_medium.mdl":
		$model = "xmodel/tree_destoyed_trunk_a";
		break;

	case "models/harvest/tree/tree_small.mdl":
		$model = "xmodel/tree_destroyed_tree_a";
		break;

	case "models/props_farm/wooden_barrel.mdl":
		$model = "xmodel/prop_barrel_tan";
		break;

	case "models/props_vehicles/pickup03.mdl":
		$model = "xmodel/vehicle_opel_blitz_desert_static";
		break;

	case "models/props_medieval/sconce.mdl":
		$model = "xmodel/light_walllight_on";
		break;

	case "models/props_trainyard/distillery_barrel001.mdl":
		$model = "xmodel/prop_barrel_tan";
		break;

	default:
		break;
	}

	return $model;
}

function getNewWeapon($weaponname)
{
	switch($weaponname)
	{
	case "weapon_ak47":
		$weapon = "weapon_mp44_mp";
		break;

	case "weapon_aug":
		$weapon = "weapon_bar_mp";
		break;

	case "weapon_awp":
		$weapon = "weapon_springfield_mp";
		break;

	case "weapon_deagle":
		$weapon = "weapon_webley_mp";
		break;

	case "weapon_elite":
		$weapon = "weapon_luger_mp";
		break;

	case "weapon_famas":
		$weapon = "weapon_thompson_mp";
		break;

	case "weapon_fiveseven":
		$weapon = "weapon_colt_mp";
		break;

	case "weapon_g3sg1":
		$weapon = "weapon_m1garand_mp";
		break;

	case "weapon_galil":
		$weapon = "weapon_m1carbine_mp";
		break;

	case "weapon_glock":
		$weapon = "weapon_tt30_mp";
		break;

	case "weapon_hegrenade":
		$weapon = "weapon_frag_grenade_american_mp";
		break;

	case "weapon_m249":
		$weapon = "weapon_ppsh_mp";
		break;

	case "weapon_m3":
		$weapon = "weapon_shotgun_mp";
		break;

	case "weapon_m4a1":
		$weapon = "weapon_mp44_mp";
		break;

	case "weapon_mac10":
		$weapon = "weapon_sten_mp";
		break;

	case "weapon_mp5navy":
		$weapon = "weapon_greasegun_mp";
		break;

	case "weapon_p228":
		$weapon = "weapon_colt_mp";
		break;

	case "weapon_p90":
		$weapon = "weapon_pps42_mp";
		break;

	case "weapon_scout":
		$weapon = "weapon_kar98k_mp";
		break;

	case "weapon_sg550":
		$weapon = "weapon_svt40_mp";
		break;

	case "weapon_sg552":
		$weapon = "weapon_m1garand_mp";
		break;

	case "weapon_smokegrenade":
		$weapon = "weapon_smoke_grenade_american_mp";
		break;

	case "weapon_tmp":
		$weapon = "weapon_thompson_mp";
		break;

	case "weapon_ump45":
		$weapon = "weapon_sten_mp";
		break;

	case "weapon_usp":
		$weapon = "weapon_luger_mp";
		break;

	case "weapon_xm1014":
		$weapon = "weapon_shotgun_mp";
		break;

	default:
		break;
	}

	return $weapon;
}

function getNewMaterial($materialname)
{
	$material = $materialname;
	$materialname = strtoupper($materialname);

	switch($materialname)
	{
	case "TOOLS/TOOLSSKYBOX":
		$material = "sky_toujane";
		break;

	case "TOOLS/TOOLSSKYBOX2D":
		$material = "sky_toujane";
		break;

	case "TOOLS/TOOLSNODRAW":
		$material = "nodraw";
		break;

	case "TOOLS/TOOLSPLAYERCLIP":
		$material = "clip_player";
		break;

	case "TOOLS/TOOLSCLIP":
		$material = "clip";
		break;

	case "TOOLS/TOOLSNPCCLIP":
		$material = "clip_ai";
		break;

	case "TOOLS/TOOLSTRIGGER":
		$material = "trigger";
		break;

	case "TOOLS/TOOLSOCCLUDER":
		$material = "occluder";
		break;

	case "TOOLS/TOOLSSKIP":
		$material = "skip";
		break;

	case "TOOLS/TOOLSHINT":
		$material = "hint";
		break;

	case "TOOLS/TOOLSINVISIBLE":
		$material = "clip_full";
		break;

	case "TOOLS/TOOLSINVISMETAL":
		$material = "clip_metal";
		break;

	case "TOOLS/TOOLSAREAPORTAL":
		$material = "portal_nodraw";
		break;

	case "TOOLS/TOOLSBLOCKLIGHT":
		$material = "shadow";
		break;

	case "TOOLS/TOOLSORIGIN":
		$material = "origin";
		break;

	case "TOOLS/TOOLSINVISIBLELADDER":
		$material = "ladder";
		break;

	case "TOOLS/TOOLSBLACK":
		$material = "mtl_caen_black";
		break;

	case "GLASS/GLASSWINDOW001A":
		$material = "clip_weap_glass";
		break;

	case "GLASS/GLASSWINDOW070C":
		$material = "clip_weap_glass";
		break;

	case "GLASS/GLASSWINDOW006B":
		$material = "stalingradwinter_window01_unlit";
		break;

	case "GLASS/GLASSWINDOW005A":
		$material = "stalingradwinter_window03";
		break;

	case "NATURE/GRASSFLOOR002A":
		$material = "mtl_caen_fullgrass_01";
		break;

	case "CS_HAVANA/GROUND01GRASS":
		$material = "mtl_caen_fullgrass_01";
		break;

	case "DE_DUST/ROCKWALL01":
		$material = "egypt_dessertrock_01";
		break;

	case "CS_HAVANA/ROCKWALL011F":
		$material = "egypt_dessertrock_01_night";
		break;

	case "NATURE/WATER_MOVINGPLANE_DX70":
		$material = "clip_water";
		break;

	case "DE_NUKE/NUKWATER_MOVINGPLANE_DX70":
		$material = "clip_water";
		break;

	case "DE_DUST/SITEBWALL14A":
		$material = "egypt_plaster_exteriorwall02";
		break;

	case "CS_HAVANA/STONEWORK01":
		$material = "mtl_caen_wall_ext_church_02_01";
		break;

	case "NATURE/BLENDGROUNDTOCOBBLE001":
		$material = "mtl_caen_floor_int_stone_01";
		break;

	case "MEDIEVAL/STONEWALL001A":
		$material = "v_stonewall02";
		break;

	case "MEDIEVAL/STONETRIM001A":
		$material = "v_stonewall04_interior";
		break;

	case "MEDIEVAL/COBBLESTONE001":
		$material = "duhoc_ground_pebbles01";
		break;

	case "MEDIEVAL/WOODTRIM001":
		$material = "stalingrad_trench_wall";
		break;

	case "WOOD/WOOD_FLOOR001":
		$material = "stalingrad_trench_wall";
		break;

	case "WOOD/WOOD_BEAM02":
		$material = "stalingrad_trench_wall";
		break;

	case "MEDIEVAL/WOOD_SHINGLES02":
		$material = "mtl_caen_roof_clay_red_01";
		break;

	case "NATURE/WATER_WASTELAND002A":
		$material = "water_elalamein";
		break;

	case "WATER/WATER_WELL":
		$material = "water_eldaba";
		break;

	case "WATER/WATER_2FORT":
		$material = "water_eldaba";
		break;

	case "METAL/IBEAM001B":
		$material = "egypt_trenchwallconcrete_b";
		break;

	case "CONCRETE/CONCRETEFLOOR001A":
		$material = "egypt_concrete_floor4";
		break;

	case "CONCRETE/CONCRETEFLOOR003":
		$material = "egypt_concrete_floor3";
		break;

	case "CONCRETE/CONCRETEWALL011":
		$material = "detail_plasterwall_top_s";
		break;

	case "CONCRETE/CONCRETEWALL011A":
		$material = "detail_plasterwall_top_s";
		break;

	case "CONCRETE/CONCRETEWALL001E":
		$material = "duhoc_concrete_multi";
		break;

	case "CONCRETE/CONCRETEWALL002":
		$material = "duhoc_block_wall1";
		break;

	case "CONCRETE/COMPUTERWALL002":
		$material = "toujane_plasterwall_white2";
		break;

	case "CONCRETE/COMPUTERWALL010":
		$material = "toujane_plasterwall_blue2";
		break;

	case "CONCRETE/COMPUTERWALL003":
		$material = "v_redvelvet";
		break;

	case "CONCRETE/COMPUTERWALL001":
		$material = "v_redvelvet";
		break;

	case "LIGHTS/WHITE001":
		$material = "d_duhoc_sky_reflect";
		break;

	case "CONCRETE/COMPUTERWALL004":
		$material = "mtl_caen_wall_ext_plaster_03_01";
		break;

	case "CONCRETE/COMPUTERWALL005":
		$material = "v_drain02_dents";
		break;

	case "NATURE/DIRTWALL001A":
		$material = "mtl_caen_mud_01";
		break;

	case "WINTER/GRASS_09":
		$material = "duhoc_dirt_ground4";
		break;

	case "NATURE/GRASSFLOOR003A":
		$material = "mtl_caen_grass_01";
		break;

	case "TILE/TILEFLOOR012A":
		$material = "toujane_cobblestone1";
		break;

	case "CONCRETE/COMPUTERWALL005A":
		$material = "egypt_plaster_interiorwall2_body";
		break;

	case "CONCRETE/CONCRETEFLOOR007B":
		$material = "mtl_caen_floor_int_church_01";
		break;

	case "BRICK/BRICKWALL001":
		$material = "mtl_caen_brick_damaged1";
		break;

	case "BRICK/BRICKWALL001C":
		$material = "mtl_caen_brick_damaged1";
		break;

	case "BRICK/BRICKWALL001D":
		$material = "mtl_caen_brick_damaged1";
		break;

	case "BRICK/BRICKWALL003A":
		$material = "mtl_caen_brick_damaged1";
		break;

	case "BRICK/BRICKWALL017A":
		$material = "mtl_caen_wall_ext_brick_02";
		break;

	case "BRICK/WALL028":
		$material = "mtl_caen_wall_churchstone_01";
		break;

	case "WOOD/WOOD_WALL003":
		$material = "dawnville2_wood_floor01";
		break;

	case "WOOD/WOOD_WALL002":
		$material = "egypt_wood_top";
		break;

	case "WOOD/WOOD_WALL001":
		$material = "dawnville2_wood_fence02";
		break;

	case "WOOD/WOOD_BRIDGE001":
		$material = "mtl_hill400_ceiling_02";
		break;

	case "WOOD/WOOD_BRIDGE002":
		$material = "stalingradwinter_wood_floor";
		break;

	case "CONCRETE/CONCRETEWALL034A":
		$material = "egypt_concrete_interiorbunker2";
		break;

	case "CONCRETE/CONCRETEWALL056B":
		$material = "mtl_caen_wall_ext_church_02";
		break;

	case "CONCRETE/CONCRETEWALL022A":
		$material = "egypt_concrete_interiorbunker1";
		break;

	case "CONCRETE/CONCRETEWALL006A":
		$material = "egypt_trenchwallconcrete_a";
		break;

	case "CONCRETE/CONCRETEWALL007A":
		$material = "egypt_trenchwallconcrete_b";
		break;

	case "PLASTER/PLASTERWALL003D":
		$material = "egypt_plaster_interiorwall6";
		break;

	case "PLASTER/PLASTERWALL005C":
		$material = "egypt_plaster_interiorwall2";
		break;

	case "PLASTER/PLASTERWALL009D":
		$material = "egypt_plaster_ceiling1";
		break;

	case "METAL/METALTRACK001A":
		$material = "egypt_metal_rebar";
		break;

	case "METAL/METALFLOOR003A":
		$material = "mtl_silo_metal_02";
		break;

	case "METAL/METALFLOOR001A":
		$material = "egypt_metal_broken_generic1";
		break;

	case "PLASTER/PLASTERCEILING005A":
		$material = "egypt_plaster_ceiling1";
		break;

	case "PLASTER/MILDOOR001":
		$material = "mtl_caen_wall_int_04_02";
		break;

	case "DE_TRAIN/TRAIN_METAL_DOOR_01":
		$material = "stalingradwinter_pipe_b";
		break;

	case "DE_NUKE/NUKDOORSB":
		$material = "mtl_hill400_door_01";
		break;

	case "DE_NUKE/NUKE_WALL_CNTRLROOM_01":
		$material = "mtl_caen_wall_int_church_02";
		break;

	case "METAL/METALDOOR055A":
		$material = "stalingradwinter_metal_floor01";
		break;

	case "DE_CHATEAU/EXTCOL01":
		$material = "egypt_concrete_floor3";
		break;

	case "DE_CHATEAU/WALL04PNL02":
		$material = "mtl_caen_wall_int_07";
		break;

	case "DE_PIRANESI/WOODFLOOR03":
		$material = "mtl_caen_floor_int_02_01";
		break;

	case "DE_CHATEAU/EXTWALL01A":
		$material = "dawnville2_wartorn_brick07";
		break;

	case "TILE/TILEFLOOR013A":
		$material = "egypt_tile1a";
		break;

	case "METAL/METALHULL003A":
		$material = "stalingradwinter_metal_roof";
		break;

	case "WOOD/OFFDESKTOPSD":
		$material = "egypt_wood_ceiling2";
		break;

	case "WOOD/OFFDESKTOP":
		$material = "egypt_wood_ceiling1";
		break;

	case "CS_ITALY/BLACK":
		$material = "mtl_caen_black";
		break;

	case "CS_ITALY/PIPE_WALL5_1":
		$material = "stalingradwinter_pipe_a";
		break;

	case "CS_ITALY/MARKETWALL01C":
		$material = "hill400_bunker_extwall01";
		break;

	default:
		break;
	}

	$materialname = strtolower($materialname);

	return $material;
}
?>
