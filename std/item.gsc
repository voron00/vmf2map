precache()
{
	precacheModel("xmodel/prop_mortar_crate2");
	precacheModel("xmodel/prop_crate_dak5");
	precacheModel("xmodel/health_small");
	precacheModel("xmodel/health_medium");
}

spawnRadiusTrigger(function)
{
	entity = self;

	entity.useTrigger = true;

	trigger = spawn("trigger_radius", entity.origin, 0, 25, 25);

	entity.trigger = trigger; // for later movement/deletion etc.

	while (1)
	{
		trigger waittill("trigger", player);

		if (entity.useTrigger == false)
		{
			wait 0.10;
			continue;
		}

		entity [[function]](player);
		wait 0.10;
	}
}

funcAmmoMedium(player)
{
	entity = self;
	entity.useTrigger = false;
	entity hide();
	
	/*
	getweaponslotweapon
	setweaponslotweapon
	getweaponslotammo
	setweaponslotammo
	getweaponslotclipammo
	setweaponslotclipammo
	setweaponclipammo
	*/
	player playsound("weap_pickup");
	if ( ! player hasWeapon(player.pers["weapon"]))
		player giveWeapon(player.pers["weapon"]);
	player giveMaxAmmo(player.pers["weapon"]);
	player switchToWeapon(player.pers["weapon"]);	
	// cant add 2/3 to current ammo, coz i cant get maxammo...
	//slot = self getweaponslotweapon(player.pers["weapon"]);
	//self setweaponslotclipammo(slot, self getweaponslotclipammo(slot) + (self getweaponslotclipammo(slot) * 2/3));
	
	wait 30;
	entity.useTrigger = true;
	entity show();
}

funcHealthMedium(player)
{
	entity = self;
	entity.useTrigger = false;
	entity hide();
	
	player playsound("weap_pickup");
	player.health += int(player.maxhealth * 0.5);
	if (player.health > player.maxhealth)
		player.health = player.maxhealth;
	
	wait 30;
	entity.useTrigger = true;
	entity show();
}
funcHealthSmall(player)
{
	entity = self;
	entity.useTrigger = false;
	entity hide();
	
	player playsound("weap_pickup");
	player.health += int(player.maxhealth * 0.25);
	if (player.health > player.maxhealth)
		player.health = player.maxhealth;
	
	wait 30;
	entity.useTrigger = true;
	entity show();
}

addItem(name, origin, angles)
{	
	model = undefined;
	switch (name)
	{
		case "item_ammopack_small":
			model = std\entity::spawnModel("xmodel/prop_mortar_crate2", origin + (0,0,0), angles);
			model thread spawnRadiusTrigger(::funcAmmoMedium);
			break;
			
		case "item_ammopack_medium":
			model = std\entity::spawnModel("xmodel/prop_crate_dak5", origin + (0,0,0), angles);
			model thread spawnRadiusTrigger(::funcAmmoMedium);
			break;
			
		case "item_healthkit_small":
			model = std\entity::spawnModel("xmodel/health_small", origin + (0,0,0), angles);
			model thread spawnRadiusTrigger(::funcHealthSmall);
			break;
			
		case "item_healthkit_medium":
			model = std\entity::spawnModel("xmodel/health_medium", origin + (0,0,0), angles);
			model thread spawnRadiusTrigger(::funcHealthMedium);
			break;
			
	}
	
	if (!isDefined(model))
		return;
	
	model thread animate();
}

animate()
{
	while (1)
	{
		self rotateYaw(360, 2);
		self moveZ(25, 2);
		wait 2;
		self rotateYaw(360, 2);
		self moveZ(-25, 2);
		wait 2;
	}
}