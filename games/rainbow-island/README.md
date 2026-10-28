# Rainbow Islands (CPC) en PHP

Remake de la version Amstrad CPC de *Rainbow Islands* (Graftgold, 1989) avec JumPHP : les 7 îles et
28 rounds, les boss, les arcs-en-ciel, la montée des eaux, l'écran de continue et la fin du jeu.
Cartes, tuiles, sprites, police, écran de chargement, musiques et bruitages sont ceux du jeu original,
extraits de la disquette avec un émulateur CPC écrit pour l'occasion (`tools/extract`).

Depuis la racine du dépôt :

```bash
bin/jumphp install rainbow-island   # dépendances
bin/jumphp run rainbow-island       # jouer sur le bureau
bin/jumphp test rainbow-island      # vérifications + test automatique sans écran
bin/jumphp build rainbow-island     # version navigateur -> dist/web
bin/jumphp serve rainbow-island     # http://localhost:8080
```

Commandes : flèches pour marcher, Haut / Z pour sauter, Espace / X pour lancer un arc-en-ciel,
P / Échap pour la pause. Une manette marche aussi (A saute, X ou B lance l'arc-en-ciel).

But du jeu : grimper jusqu'au panneau G avant que l'eau monte. Un arc-en-ciel assomme les ennemis
qu'il touche en apparaissant (ils deviennent des fruits) ; on peut marcher dessus, et en retombant
dessus après un saut on le casse : il tombe, et les ennemis qu'il écrase deviennent des diamants
dont la couleur dépend de la colonne de l'écran. Au 4e round de chaque île, un boss attend en haut.
