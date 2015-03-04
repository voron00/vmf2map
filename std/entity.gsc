setAlive(state)
{
	entity = self;
	if (state)
		closer(7, entity getEntityNumber(), 1);
	else
		closer(7, entity getEntityNumber(), 0);
}

spawnModel(name, origin, angles)
{
	model = spawn("script_model", origin);
	model.angles = angles;
	model setModel(name);
	return model;
}