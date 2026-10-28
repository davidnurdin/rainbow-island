# Brief: rainbow island

You are working in a JumPHP project: retro 2D / 3D games in PHP that run on the desktop and in the browser.
Read AGENTS.md first (the framework contract: rules, commands, API), then games/rainbow-island/AGENTS.md.

The game is **games/rainbow-island** ("rainbow island"). It was created from the skeleton (a tiny platformer): replace its
scenes, entities, texts and sounds with this game:

j'aimerais que tu me crée le jeu rainbow island, regarde le fichier dsk dans /home/david/Bureau/rainbow.zip, decompile le , lance le via un emulateur amstrad que tu aura fait si il faut, je veux les meme assets, les meme musique sprite ect. decompile le et je veux que le jeux soit lisible (sa source) comme si c'est un humain qui l'avait fait.

How to work:
- Everything runs through bin/jumphp (Docker). Never install PHP or run php / composer on the host.
- Look at games/pong (menus, AI, local and online play) and games/jumpball (3D with WebGL) for models.
- Iterate with bin/jumphp test rainbow-island and screenshots (bin/jumphp shot rainbow-island ...): open the PNGs and check
  that what you see is what you meant.
- Done means AGENTS.md section 10: tests pass, screenshots look right, menus / pause / game over work,
  bin/jumphp lint passes, bin/jumphp build rainbow-island + bin/jumphp webshot rainbow-island pass, and games/rainbow-island/AGENTS.md +
  README.md describe the game.
- Ask me before publishing anything (git push, bin/jumphp deploy, bin/jumphp pages).
