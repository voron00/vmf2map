vmf2map
========
This script is used to convert .vmf (Valve Map Format) files to CoD's IW Engine .map

Script was originally written by kung foo man and edited by Mitch, johndoe at
http://killtube.org/showthread.php?1003-VMF2Map

Health/ammo packs mod taken from kung foo man's surf mod, so all credits to him

Also huge thanks to kung foo man for patched cod2map.exe with MAX_MAP_LIGHTBYTES error fixed

I've updated the script a little bit to provide more user-friendly converting,
added light exporting, weapon exporting from Counter Strike:Source maps,
many new textures, fixes and other stuff.

Note: This script was originally aimed to porting some surf maps,
but my version is probably not the best choice for a surf maps.
My goal is to port as many entities on a map as possible with
appropriate textures, models, etc. so the map would look
as close as possible to its original version and then finalize
the map in Radiant.

Also i've made materials only for CoD2, they will not work
in CoD4, CoD5 and will probably cause errors.

Usage:

php vmf2map.php yoursourcemap.vmf

If you need to decompile Source engine maps(.bsp),
use latest version of BSPSource decompiler
http://ata4.info/bspsrc/downloads.html
