spawnModel(name, origin, angles)
{
    model = spawn("script_model", origin);
    model.angles = angles;
    model setModel(name);
    return model;
}