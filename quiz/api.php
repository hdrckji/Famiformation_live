<?php
/* ============================================================
   ⚙️ API DU QUIZ — côté serveur (IONOS ou Railway)
   Stocke les scores et les codes bonus dans des fichiers JSON.

   SIMULTANÉITÉ : plusieurs personnes jouent en même temps (c'est même le cas
   normal le jour de l'événement). Toute opération qui MODIFIE un fichier garde
   donc UN SEUL verrou exclusif du début à la fin — lecture ET écriture comprises.
   Sinon deux joueurs qui valident à la même seconde liraient la même liste et le
   second écraserait le premier : un score perdu, ou un code bonus donné deux fois.
   ============================================================ */

header('Content-Type: application/json; charset=utf-8');

// 🛟 FILET DE SÉCURITÉ : mbstring est présent sur IONOS comme sur l'image Railway,
// mais s'il venait à manquer, mb_strlen() serait « fonction inconnue » → erreur
// fatale renvoyée en HTTP 200, c'est-à-dire un score perdu SANS que le joueur
// voie la moindre erreur. On préfère un repli ASCII (légèrement moins exact sur
// les accents) plutôt qu'une panne muette le jour de l'événement.
if (!function_exists('mb_strlen')) {
  function mb_strlen($s, $enc = null) { return strlen((string)$s); }
}
if (!function_exists('mb_strtolower')) {
  function mb_strtolower($s, $enc = null) { return strtolower((string)$s); }
}
if (!function_exists('mb_substr')) {
  function mb_substr($s, $debut, $len = null, $enc = null) {
    return $len === null ? substr((string)$s, $debut) : substr((string)$s, $debut, $len);
  }
}

// 🔑 Codes bonus à usage unique (les mêmes que sur tes QR codes en magasin).
// 20 codes, chacun rapporte $CODE_GRAINES graines à la PREMIÈRE personne qui le
// récupère. Chaque joueur peut en cumuler au maximum $MAX_CODES.
// ⛳ DEUX MAGASINS, DEUX LOTS DE CODES DISTINCTS. Les QR de Mouscron (préfixe
// FAMI-) et ceux de La Panne (préfixe FAPA-) n'ont aucun code en commun : un
// code trouvé à Mouscron est « inconnu » à La Panne, et réciproquement. Les deux
// événements ne peuvent donc pas se mélanger, même par erreur de manipulation.
$BONUS_CODES_PAR_SITE = [
  'mouscron' => [
    "FAMI-A7K2", "FAMI-B3X9", "FAMI-C5M1", "FAMI-D8R4", "FAMI-E2T7",
    "FAMI-F6H8", "FAMI-G1J3", "FAMI-K9L2", "FAMI-M4N7", "FAMI-P5Q8",
    "FAMI-R3S6", "FAMI-T2U9", "FAMI-V7W1", "FAMI-X8Y4", "FAMI-Z5A2",
    "FAMI-B9C6", "FAMI-D1E3", "FAMI-F4G7", "FAMI-H8J5", "FAMI-K2L9",
  ],
  'lapanne' => [
    "FAPA-N3B7", "FAPA-C8D2", "FAPA-E5F9", "FAPA-G1H4", "FAPA-J6K3",
    "FAPA-L2M8", "FAPA-P7Q1", "FAPA-R4S9", "FAPA-T3U6", "FAPA-V8W2",
    "FAPA-X5Y7", "FAPA-Z1A4", "FAPA-B6C9", "FAPA-D2E5", "FAPA-F8G3",
    "FAPA-H4J7", "FAPA-K9L1", "FAPA-M3N6", "FAPA-P5Q8", "FAPA-R2S4",
  ],
];
// 🧪 Codes de TEST (communs aux deux sites) : le premier marche toujours (jamais
// consommé, re-testable), le second est toujours vu comme « déjà utilisé ».
$CODE_TEST_OK   = "FAMI-TEST-OK";
$CODE_TEST_USED = "FAMI-TEST-USED";
// $BONUS_CODES est fixé plus bas, une fois le site de la requête connu.
// 🧪 COMPTES DE TEST : ces pseudos servent a essayer le jeu EN VRAI (borne,
// telephone, ordi) sans deranger personne. Ils n'apparaissent PAS au classement
// public ni sur la tele, et ils peuvent refaire le quiz autant de fois qu'ils
// veulent. Cree-les comme un compte normal avec ce pseudo.
// ⚠️ LISTE EXACTE, PAS UN PRÉFIXE. Seuls ces identifiants précis sont des
// comptes de service. « admin_ » est le compte d'essai ; il ne s'agit PAS de
// tous les comptes administrateurs.
//
// La règle a été en préfixe pendant un temps : tout ce qui commençait par
// « admin_ » était écarté. Résultat, un ou une collègue avec un profil admin
// qui joue pour de vrai — identifiant « admin_sophie » par exemple — était
// silencieusement retiré du classement, alors qu'il ou elle a parfaitement le
// droit de concourir. Pour ajouter un compte de service, écris son identifiant
// complet ici.
$COMPTES_TEST = ['testeur', 'admin_'];
function estCompteTest($p) {
  global $COMPTES_TEST;
  $nom = mb_strtolower(trim((string)(is_array($p) ? ($p['name'] ?? '') : $p)));
  return in_array($nom, $COMPTES_TEST, true);
}

/**
 * 👋 Le prénom tel qu'on l'écrit en tête d'un mail.
 *
 * Les prénoms viennent de la base Famiformation, alimentée en partie par des
 * imports Excel : on y trouve « ENYLSON » ou « enylson » aussi souvent que
 * « Enylson ». Écrit tel quel, le mail commence par « Bonjour ENYLSON, », ce
 * qui donne l'impression de crier sur la personne qu'on félicite.
 *
 * ⚠️ On ne retouche QUE les prénoms entièrement en majuscules ou entièrement en
 * minuscules — ceux des imports. Tout ce qui a une casse voulue est laissé
 * intact : « McDonald », « van Damme », « d'Hondt » ne doivent pas être
 * « corrigés » en quelque chose que la personne n'écrit pas comme ça.
 */
function prenomAffichable($prenom) {
  $p = trim(preg_replace('/\s+/u', ' ', (string) $prenom));
  if ($p === '') { return ''; }
  $bas  = mb_strtolower($p, 'UTF-8');
  $haut = mb_strtoupper($p, 'UTF-8');
  if ($p !== $bas && $p !== $haut) { return $p; }   // casse voulue : on n'y touche pas
  // MB_CASE_TITLE met aussi la majuscule après un trait d'union ou une
  // apostrophe : « jean-marc » → « Jean-Marc », « o'brien » → « O'Brien ».
  return mb_convert_case($bas, MB_CASE_TITLE, 'UTF-8');
}

// ⭐ FAVORITES DES QUESTIONS « ENTREPRISE » VENANT DE LA BASE.
// Ces questions sont importées de la table quiz_questions, qui n'a pas de
// colonne « favorite ». On les repère donc par leur texte, à la réinstallation.
// Comparaison souple (accents, apostrophes et ponctuation ignorés) : le libellé
// exact varie d'une saisie à l'autre.
$FAVORIS_TEXTES = [
  // ⚠️ CETTE LISTE EST LA SEULE MÉMOIRE DES FAVORITES « ENTREPRISE ».
  // Ces questions viennent de la base Famiformation, qui n'a pas de colonne
  // « favorite » : une étoile mise à la main dans /quiz/admin est perdue à la
  // prochaine réinstallation. Il n'y avait plus qu'UNE ligne ici alors que le
  // quiz en ligne comptait 10 favorites — réinstaller les questions en aurait
  // effacé 9. Toute nouvelle favorite entreprise doit être ajoutée ICI.
  // La comparaison ignore la casse, les accents et la ponctuation.
  'combien y a t il de valeurs dans l entreprise',
  "En quelle année Famiflora a-t-elle été créée ?",
  "Combien de places de parking compte le site de Mouscron ?",
  "Combien de produits différents porte la marque Famiflora ?",
  "Combien de bonbons différents trouve-t-on chez Lollyland ?",
  "Combien de variétés de bières différentes propose l'Abbaye ?",
  "Combien de clients accueillons-nous environ sur la saison d'hiver ?",
  "Combien de clients accueillons-nous environ au printemps, de mars à fin mai ?",
  "Combien de nationalités différentes travaillent chez Famiflora ?",
  "D'où vient la majorité de nos collègues qui travaillent sur le site de Mouscron ?",
  // Questions « identité Famiflora » : elles parlent du magasin tel qu'on le
  // voit en y travaillant. Elles élargissent le vivier de favorites, qui était
  // trop étroit : 5 tirées parmi 10 revenaient presque toujours les mêmes.
  "Famiflora se définit comme un Garden Center, mais aussi comme...",
  "Quel secteur occupe une place centrale dès l'entrée du magasin ?",
  "Que peut-on trouver dans le secteur \"Terroir\" de Famiflora ?",
  "Famiflora possède un rayon dédié aux animaux, comment s'appelle-t-il ?",
  "Quelle est la particularité du secteur fleurs coupées ?",
  "Quel est l'événement majeur de fin d'année chez Famiflora ?",
  "Que trouve-t-on dans le secteur \"Pépinière\" extérieur ?",
  "Famiflora propose de quoi se restaurer, comment s'appelle cet espace ?",
  "Pourquoi les serres immenses sont-elles un avantage ?",
  "Famiflora est situé dans quelle zone géographique ?",
];

/**
 * 👤 PROFIL D'UN NOUVEAU COMPTE créé depuis le quiz.
 *
 * Par défaut « beta ». Mais quelqu'un qui figure dans la liste du personnel
 * n'est pas un visiteur : il entre directement avec le profil employé, sans
 * passer par la case beta ni attendre un tri manuel.
 *
 * La règle ne vaut qu'à partir du 29/07/2026 12h30 (voir personnel_liste.php) :
 * avant cette heure, tout le monde reste en beta comme prévu.
 */
function roleInscription($prenom, $nom) {
  global $SITE;
  // 🏬 LA PANNE : tous les inscrits du quiz — borne comme téléphone — reçoivent
  // le profil « betalapanne ». C'est une bêta À PART : elle n'ouvre que
  // « Quiz & mon espace jardin », sans les modules de la bêta classique
  // (Onboarding, Magasin), qui ne concernent pas ce magasin pour l'instant.
  // Volontairement AVANT la reconnaissance du personnel : à La Panne, même
  // quelqu'un présent dans la liste du personnel entre par cette porte-là.
  if ($SITE === 'lapanne') { return 'betalapanne'; }
  if (!function_exists('personnelTrouve') || !function_exists('personnelRegleActive')) { return 'beta'; }
  if (!personnelRegleActive()) { return 'beta'; }
  return personnelTrouve($nom, $prenom) ? personnelRoleCible() : 'beta';
}
function normaliseTexteQuestion($t) {
  $t = mb_strtolower(trim((string) $t));
  $t = strtr($t, ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                  'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
  $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
  return trim(preg_replace('/\s+/', ' ', $t));
}
function estFavoriParTexte($q) {
  global $FAVORIS_TEXTES;
  $n = normaliseTexteQuestion($q);
  foreach ($FAVORIS_TEXTES as $ref) {
    if ($n === normaliseTexteQuestion($ref)) { return true; }
  }
  return false;
}

/**
 * 🚫 QUESTIONS ÉCARTÉES DU QUIZ.
 *
 * Elles restent dans la base Famiformation (elles ont leur place dans les
 * formations, où l'on a le support sous les yeux), mais elles n'ont rien à faire
 * dans un quiz joué en magasin : impossible d'y répondre sans avoir la vidéo, le
 * document ou la fiche produit devant soi, ou sans avoir suivi cette formation-là.
 *
 * On les écarte PAR LEUR TEXTE, à la réinstallation des questions : rien n'est
 * supprimé, il suffit de retirer une ligne d'ici pour qu'une question revienne.
 */
$QUESTIONS_EXCLUES = [
  // Renvoient à un support que le joueur n'a pas.
  "Quelle est la mission principale de Famiflora présentée dans la vidéo ?",
  "Où le client doit-il se rendre une fois muni de son article et du document ?",
  "Le message final du document est :",
  // Formation Becosoft : incompréhensible sans la fiche article ouverte.
  "À quoi correspond la valeur \"Vfami\" dans la fiche article ?",
  "Que signifie l'onglet \"Voorraadlocaties\" dans la fiche article ?",
  // Parcours de formation numéroté jour par jour : hors sujet pour qui ne l'a pas suivi.
  "Jour 2 concerne :",
  "Jour 4 concerne :",
  "Jour 5 concerne :",
  "Jour 6 concerne:",
  "Jour 7 sert à :",
  "Jour 8 concerne:",
  // Énoncés tronqués : la question ne se suffit pas à elle-même.
  "La marraine est :",
  "Le gerbeur est :",
  "La Rose de Leary est :",
  "Les grilles Napoléon sont :",
  "Bon charbon =",
  "Le modèle Nestor est :",
  "Les fixations de couverture sont :",
  "Les LED sont :",
  "Le panneau de commande est :",
  "La bande LED est :",
  "Le PureSpa Glow est :",
  "Le design du PureSpa Glow est :",
  // Saisie accidentelle.
  "kjjnknk",

  // Role « relais marketing » — 19 questions.
  "Le relais marketing est un point de :",
  "Le relais marketing fait le lien entre :",
  "Le relais marketing aide à :",
  "Le relais marketing propose :",
  "Le relais marketing travaille sur :",
  "Le relais marketing est force de :",
  "Pourquoi solliciter du relais marketing ?",
  "L'objectif du relais marketing est :",
  "Le relais marketing contribue à :",
  "Le relais marketing participe à :",
  "Le relais marketing fait vivre :",
  "Le relais marketing centralise :",
  "Le relais marketing est disponible pour :",
  "Le relais marketing accompagne :",
  "Le relais marketing travaille en lien avec :",
  "Le relais marketing propose des idées :",
  "Le relais marketing peut aider pour :",
  "Le relais marketing suit :",
  "Le rôle du relais marketing principal est :",

  // Outil « fichier de suivi » — 4 questions.
  "Le fichier de suivi sert à :",
  "Le fichier de suivi permet de :",
  "Dans le fichier de suivi on y note :",
  "L’objectif du fichier de suivi est :",

  // Programme de marrainage — 8 questions.
  "La marraine doit :",
  "La marraine aide à :",
  "La marraine doit créer :",
  "La marraine transmet :",
  "La marraine encourage :",
  "La marraine doit répondre :",
  "La marraine fait :",
  "La marraine observe pour :",

  // Parcours jour par jour — 5 questions.
  "Jour 1 correspond à :",
  "Jour 1: On apprend à :",
  "Jour 1: On découvre :",
  "Jour 3 est dédié à :",
  "Jour 7, on vérifie :",

  // Gamme barbecue — 37 questions.
  "Que possèdent tous les BBQ pour protéger les brûleurs ?",
  "Que fait la fonte lorsqu’on éteint le BBQ ?",
  "De quoi est fait le couvercle des BBQ Weber ?",
  "De quoi est faite la cuve des BBQ Weber ?",
  "Peut-on fermer le BBQ en cuisant des aliments gras ?",
  "Pourquoi ne faut-il pas fermer le BBQ avec des aliments gras ?",
  "Que peut contenir le meuble sous le BBQ ?",
  "Pourquoi le meuble sous le BBQ est-il ventilé ?",
  "Quels BBQ peuvent être transformés en plancha ?",
  "Comment transformer ces BBQ en plancha ?",
  "Que nécessitent les petits BBQ portatifs ?",
  "Quel type de cuisson est fréquent avec les BBQ à pellet ?",
  "Quelle est l’origine de la marque Napoleon ?",
  "Pourquoi la marque Napoleon s’appelle-t-elle ainsi ?",
  "En général, Napoleon est moins cher :",
  "De quelle couleur sont les nouveaux couvercles des BBQ gaz Napoleon ?",
  "La cuve des BBQ Napoleon est en :",
  "Garantie de la cuve Napoléon :",
  "Garantie des brûleurs Napoléon :",
  "Le « pont » entre les brûleurs Napoléon sert à :",
  "Le système d’allumage Napoléon s’appelle :",
  "Pourquoi utiliser une feuille d’aluminium dans le BBQ ?",
  "Après pyrolyse, il faut :",
  "Une face des grilles Napoléon sert à :",
  "La Sizzle Zone permet :",
  "Température de la Sizzle Zone :",
  "On peut aussi utiliser la Sizzle Zone pour :",
  "Le détendeur Napoléon et le tuyau sont :",
  "On peut transformer un BBQ gaz en :",
  "Sur BBQ charbon Pro : hauteur de grille :",
  "BBQ électrique : il faut :",
  "Les housses sont plus courtes pour :",
  "Pourquoi vider un BBQ charbon ?",
  "Dans un brasero, on utilise :",
  "Les BBQ Barbecook sont en :",
  "Température idéale grillade :",
  "Un BBQ fermé permet :",

  // Gamme spa / piscine — 28 questions.
  "Avec quel produit doit être utilisé Oxy Pool & Spa ?",
  "Quel est le nouveau coloris du PureSpa Bubble Massage ?",
  "Le PureSpa Bubble Massage est compatible avec :",
  "Le PureSpa Glow est conçu pour combien de personnes ?",
  "Le PureSpa Glow possède :",
  "Combien de LED possède le PureSpa Glow ?",
  "Les LED sont alimentées par :",
  "Le PureSpa Glow dispose de :",
  "Le spa utilise quel système d’eau ?",
  "La cartouche du PureSpa Glow est :",
  "Indice de protection du spa :",
  "Les lumières LED peuvent être :",
  "Les LED sont contrôlées via :",
  "Le spa est décrit comme :",
  "La connexion LED se fait via :",
  "L’alimentation LED est :",
  "Le PureSpa Bubble Massage est considéré comme :",
  "Le spa Bubble Massage conserve :",
  "Le système LED permet :",
  "Le spa est alimenté en LED :",
  "Le PureSpa Glow est décrit comme :",
  "Le panneau WiFi est compatible avec :",
  "Le spa est résistant :",
  "Le PureSpa Bubble Massage offre :",
  "Le système de stérilisation est :",
  "Les LED sont placées :",
  "Le spa fonctionne avec :",
  "Le PureSpa Glow appartient à la gamme :",


  // ============================================================
  // 2e VAGUE — le grand menage. Les questions ci-dessus ne suffisaient
  // pas : l'immense majorite du reservoir venait telle quelle des
  // supports de formation (menus de logiciel, fiches fournisseur,
  // chiffres lus dans une video). On garde desormais UNIQUEMENT ce
  // qu'un collaborateur peut trouver par bon sens, par observation du
  // magasin ou par culture du metier.
  // ============================================================
  // Fiches produit barbecue et plancha : specifications, materiaux, garanties et
  // modeles. Impossible a deviner sans la fiche du fournisseur sous les yeux.
  "Condition pour la garantie :",
  "De quel matériau est faite la poignée ?",
  "De quel matériau sont faits les protecteurs de brûleurs ?",
  "De quoi le SAV Weber a-t-il toujours besoin ?",
  "Les anciennes petites grilles étaient en :",
  "Les nouvelles petites grilles sont en :",
  "Où sont conservées les vis ?",
  "Par quel intermédiaire fonctionne la garantie ?",
  "Peut-on utiliser des spatules en métal sur les planchas en fonte ?",
  "Pour le SAV, il faut :",
  "Pour le SAV, il faut fournir :",
  "Pourquoi la fonte d’aluminium est-elle utilisée pour la cuve ?",
  "Pourquoi les protecteurs de brûleurs sont-ils utiles ?",
  "Pourquoi les vis des plaques mortuaires sont-elles retirées ?",
  "Pourquoi vider le chariot ?",
  "Quand doit-on rentrer la planche en bambou ?",
  "Que faut-il faire des vis des plaques mortuaires ?",
  "Que faut-il faire du pellet après utilisation ?",
  "Que font les protecteurs de brûleurs avec la graisse ?",
  "Quel avantage ont les dessertes Cook’in Garden ?",
  "Quel est l’objectif du chariot d’invendus ?",
  "Quelle particularité a la gamme Performer ?",
  "Version avec chariot permet :",
  "À quoi sert la planche en bambou sur certaines planchas ?",
  "À quoi servent les ailettes et le thermomètre numérique ?",
  // Logiciel Becosoft, scanner Zebra, bons de commande, ventes flash : ce sont des
  // suites de menus et de boutons. Sans le logiciel devant soi, personne ne peut repondre.
  "Après avoir choisi \"Sortant\", quelle option faut-il sélectionner ?",
  "Combien de références maximum dans un seau de fleurs séchées ?",
  "Combien d’articles de chaque référence sont placés d’abord ?",
  "Comment finaliser une demande d'étiquettes sur le Zebra ?",
  "Comment identifie-t-on l'article sur le Zebra pour la vente flash ?",
  "Comment imprimer une étiquette rayon depuis l'ordinateur ?",
  "Comment procéder pour une remise sur un article défectueux ?",
  "Comment s'appelle l'étiquette autocollante simple dans le logiciel ?",
  "Dans quel cas précis peut-on créer une vente flash pour un client ?",
  "Dans quel menu de Becosoft CRM se trouve la fonction pour chercher un produit ?",
  "En cas de problème imprimante :",
  "Le scan rayon sert à :",
  "Lors de l'encodage d'un bon pour du gazon artificiel, que représente la \"quantité\" ?",
  "Où doit-on indiquer la raison de la réduction lors d'une vente flash sur Zebra ?",
  "Où se rend-elle après avoir récupéré le scan ?",
  "Peut-on appliquer une remise manuelle sur un article ?",
  "Peut-on imprimer une affiche A4 directement depuis Becosoft ?",
  "Pour créer une liste d'étiquettes à traiter plus tard sur PC, quel menu Zebra utilise-t-on ?",
  "Pour une livraison, que faut-il faire si l'adresse de livraison est différente de la facturation ?",
  "Pourquoi chaque référence est-elle mise en place ?",
  "Pourquoi place-t-on un article de chaque référence ?",
  "Que faut-il faire après avoir appliqué une remise ?",
  "Que faut-il saisir dans la fenêtre \"Modifier la remise\" ?",
  "Que se passe-t-il immédiatement après avoir cliqué sur \"Traiter\" sur le Zebra ?",
  "Que se passe-t-il une fois que l'on a cliqué sur \"Traiter\" ?",
  "Quel bouton permet de clôturer définitivement la vente sur le Zebra ?",
  "Quel est l'objectif principal de la formation Vente Flash ?",
  "Quel est le délai de livraison moyen pour le gazon artificiel après commande ?",
  "Quel est le montant de l'acompte standard à régler à l'accueil pour un bon de commande ?",
  "Quel est le nom néerlandais de l'étiquette \"papillon\" ?",
  "Quel jour les livraisons de gazon naturel sont-elles effectuées ?",
  "Quel menu doit-on choisir sur le Zebra pour consulter un article ?",
  "Quel outil doit-on utiliser pour calculer les frais de livraison d'un client ?",
  "Quel produit fait l'objet d'un cas particulier pour la vente flash ?",
  "Quelle est l'utilité d'encoder le numéro de TVA d'une société dans Becosoft ?",
  "Quelle est la condition de valeur minimale pour créer un bon de commande ?",
  "Quelle est la condition pour accorder une remise de 50% sur une vente flash ?",
  "Quelle est la première option à choisir dans le menu du Zebra pour débuter ?",
  "Quelle est la procédure correcte sur Zebra avant de scanner les articles ?",
  "Quelle fenêtre s'ouvre automatiquement après le scan de l'article ?",
  "Quelle méthode permet d'imprimer plusieurs étiquettes différentes à la suite sur Zebra ?",
  "Sur Zebra, que doit-on indiquer dans le champ \"Référence\" d'une demande d'étiquettes ?",
  "Sur l'ordinateur, dans quel menu faut-il aller pour créer un bon de commande ?",
  "Sur le Zebra, où trouve-t-on le bouton \"Print label\" ?",
  "Sur le Zebra, quel est le premier choix à faire dans le menu principal ?",
  "Une fois traitée sur Zebra, où retrouve-t-on la demande d'étiquettes sur PC ?",
  "Une étiquette doit être :",
  "À quoi sert en parti le scan ?",
  // Implantations et reimplantations de rayons : deroule interne d'un chantier precis.
  "Comment afficher l'ensemble des articles d'un groupe spécifique ?",
  "Comment les palettes sont-elles organisées ?",
  "Où se trouve la palette prioritaire des saisons dans le stock ?",
  "Pourquoi analyse-t-on la structure du secteur ?",
  "Pourquoi les affiches sont-elles déjà installées sur les bacs volume ?",
  "Pourquoi les palettes sont-elles triées ?",
  "Pourquoi prépare-t-on les implantations à l’avance ?",
  "Que doit-on aussi vérifier sur la palette ?",
  "Que facilite l’organisation des palettes par métrage ?",
  "Que fait-on après avoir refilmé la palette ?",
  "Que fait-on une fois la palette terminée ?",
  "Que trouve-t-on également sur la palette ?",
  "Que vérifie-t-on au dépôt ?",
  "Que vérifie-t-on sur la palette ?",
  "Quel est le montant de la caution par palette lors de l'enlèvement du gazon naturel ?",
  "Quel est le rôle de l’équipe décoration ?",
  "Quel est l’objectif des supports marketing ?",
  "Quel outil est utilisé pour préparer les futures implantations ?",
  "Quel outil utilise-t-on pour déplacer la palette ?",
  "Quel univers remplace celui de Pâques ?",
  "Quelle palette est donnée dans l’exemple ?",
  "Qui démonte les rayonnages ?",
  "Qui réalise la décoration des vases ?",
  "Si le client ne souhaite pas payer la caution palette, comment s'effectue le chargement ?",
  "À partir de quel mois commence Halloween ?",
  // Produits piscine de marque (Fast, Crystal Clear, Calc Free, Aquapur, Mini Pool Set) :
  // il faut la fiche produit, pas du raisonnement.
  "Dans quelle situation utilise-t-on généralement le produit Fast ?",
  "Pour quel type de piscine utilise-t-on le Mini Pool Set ?",
  "Quel est l’inconvénient de l’Aquapur 5 en 1 ?",
  "À quelle fréquence doit-on utiliser le Mini Pool Set ?",
  "À quoi sert le produit Calc Free ?",
  // Secteur fleurs artificielles : fournisseurs, tables, emplacements et prenoms tires
  // de la video de formation (Calista, Lien, Team Deco, Jasaco, Decostar, Louis Maes).
  "Avec quoi monte-t-on les plantes Bestdeal ?",
  "Combien de plantes retombantes maximum par crochet ?",
  "Combien de tables Louis Maes existe-t-il ?",
  "Combien de tables avec tiges y a-t-il ?",
  "Combien de tiges minimum faut-il pour soutenir un carré végétal ?",
  "Comment faut-il organiser les fleurs séchées ?",
  "Dans quel délai un client doit-il enlever sa marchandise après réception en magasin ?",
  "Les paniers aident les clients à :",
  "Les tiges Louis Maes arrivent-elles déjà étiquetées ?",
  "Où se trouve le pistolet à colle ?",
  "Où se trouvent les différents lots de tiges ?",
  "Où se trouvent les phrases des plaques mortuaires ?",
  "Où se trouvent les potées en plastique mortuaires ?",
  "Où se trouvent les prix sur les articles Louis Maes ?",
  "Où sont stockées les commandes clients pour le secteur \"Déco\" ?",
  "Où sont stockées les commandes clients pour le secteur \"Green & Garden\" ?",
  "Où stocke-t-on les plantes Bestdeal déballées ?",
  "Pourquoi Team Deco récupère-t-elle les dernières pièces de tiges ?",
  "Pourquoi la manutention est-elle importante sur les tiges ?",
  "Que consulte-t-elle après avoir badgé ?",
  "Que faire lorsqu'un client présente une carte de réduction à la caisse ?",
  "Que fait le client après avoir payé une phrase mortuaire ?",
  "Que fait-on des dernières pièces des tiges ?",
  "Que met Calista avant de commencer ?",
  "Que récupère Calista en magasin ?",
  "Quel est le rôle de Lien ?",
  "Quel fournisseur correspond à “CountryField” ?",
  "Quelle est la première action de Calista ?",
  "Quelle information est indispensable sur une fiche client pour assurer le suivi ?",
  "Quelles tiges doivent être étiquetées avec Kleeft + prix ?",
  "Qui remplit les neuvaines ?",
  // Parcours d'integration et journee type du secteur Mix : horaires, numeros de palette,
  // dates d'exemple, tableaux internes. Ce sont des reponses tirees d'un support.
  "Avec quoi transporte-t-on les invendus ?",
  "Dans quel secteur travaille Audrey ?",
  "Le débrief final sert à :",
  "Le facing doit être :",
  "Le facing signifie :",
  "Le stock Beco sert à :",
  "Le tableau caisse sert à :",
  "L’autonomie signifie :",
  "L’intégration réussie repose sur :",
  "Quand consulte-t-on le planning Mix / Polyvalent ?",
  "Quel est le rôle d’Audrey ?",
  "Quel est le rôle principal de la marraine ?",
  "Quel est l’objectif principal du local des invendus ?",
  "Quelle est la tâche suivante après les invendus ?",
  "Un article sans EAN doit être :",
  "À quelle heure commence la journée au mix ?",
  "“Négatif” signifie :",
  // Gazon a la coupe : procedure de commande, delais, cautions et tarifs fournisseur.
  "Est-il possible de faire livrer le gazon artificiel directement par le fournisseur ?",
  "Où faut-il indiquer les dimensions précises (largeur x longueur) du gazon artificiel ?",
  "Par quelles tranches de longueur peut-on commander du gazon artificiel ?",
  "Pour le gazon artificiel, quelle est la surface minimale de commande par rouleau ?",
  "Quel est le jour limite pour clôturer une commande de gazon naturel pour la semaine en cours ?",
  "Quelle contrainte peut empêcher la coupe du gazon naturel chez le fournisseur ?",
  "Quelle est la condition impérative pour valider une commande de gazon (naturel ou artificiel) ?",
  "Quelle est la dimension standard d'un rouleau de gazon naturel chez Famiflora ?",
  "Quelles sont les largeurs disponibles pour le gazon artificiel ?",
  "À partir de quel jour et quelle heure le gazon naturel est-il disponible pour l'enlèvement ?",
  // Spas et accessoires : specifications produit.
  "Comment l’espace final doit-il accueillir la saison estivale ?",
  "Le kit de conversion au sel est :",
  "Le passage d’air sert à :",
  "Quelle est la nouvelle cartouche mentionnée ?",
  // Divers : questions renvoyant a un document, une video ou une procedure precise.
  "Après les paniers, que fait-on ?",
  "Combien de paniers compose une pile ?",
  "Combien de temps dure la pause principale ?",
  "Combien de temps les produits sont-ils garantis ?",
  "Comment doit-on remplir le rayon ?",
  "Comment est décrit le résultat final ?",
  "Comment s'appelle la collaboratrice présentée ?",
  "Comment travaille-t-on les plantes intérieures ?",
  "Devant un produit il faut :",
  "En quelle année le rayon des fleurs artificielles a-t-il été déplacé ?",
  "La communication doit être :",
  "Les améliorations incluent :",
  "Lors de la vente, que faut-il mettre dans le fond de la boîte de transport de l'animal ?",
  "L’objectif final est :",
  "Où apparaît la photo de l'article lorsqu'on le sélectionne dans la liste ?",
  "Où doit-on indiquer la raison de la réduction (ex: statue fissurée) ?",
  "Où les bouquets sont-ils fabriqués ?",
  "Où note-t-on la mise à jour du travail ?",
  "Où sont placés les bouquets séchés ?",
  "Où sont rangés les paniers en surplus ?",
  "Où trouve-t-on les missions de la journée ?",
  "Pour les souris, gerbilles, hamsters, rats et octodons, où faut-il les déposer à l'accueil ?",
  "Pour quel type de produit le paiement total est-il exigé dès la commande ?",
  "Pourquoi ce moment de pause est-il important ?",
  "Pourquoi commence-t-on tôt le matin ?",
  "Pourquoi garder le local propre ?",
  "Pourquoi inscrire une nouvelle date ?",
  "Pourquoi les produits sont-ils faciles à monter ?",
  "Pourquoi l’équipe effectue-t-elle des tours en magasin ?",
  "Pourquoi ramasser ce qui traîne ?",
  "Pourquoi remplir les piles de paniers ?",
  "Pourquoi stocker des paniers en réserve ?",
  "Pourquoi utiliser les produits dormants ?",
  "Pourquoi vérifier ces informations ?",
  "Pourquoi y a-t-il souvent beaucoup de paniers ?",
  "Quand les orchidées ont-elles beaucoup de succès ?",
  "Quand privilégie-t-on les travaux importants ?",
  "Que doit-on faire avant d’entrer dans un stock ?",
  "Que doit-on vérifier dans le rayon ?",
  "Que doit-on vérifier pour les pastilles parfumées ?",
  "Que fait-on avec les paniers laissés la veille ?",
  "Que fait-on avec l’ancienne date ?",
  "Que fait-on dans le local après les rotations ?",
  "Que fait-on pendant le remplissage du rayon ?",
  "Que fait-on une fois arrivé dans le rayon ?",
  "Que fait-on une fois dans le rayon ?",
  "Que fait-on une fois le rayon vidé ?",
  "Que faut-il faire si l’eau est trouble mais pas verte ?",
  "Que ne faut-il jamais encombrer ?",
  "Que peut-on saisir dans la barre de recherche pour trouver un article ?",
  "Que respecte-t-on lors du remplissage ?",
  "Que sont installés après la mise en place des produits ?",
  "Que vérifie-t-elle au stock ?",
  "Quel accessoire est inclus ?",
  "Quel arbre retrouve-t-on en été ?",
  "Quel avantage principal a apporté le nouvel emplacement ?",
  "Quel est l’objectif de toutes ces pratiques ?",
  "Quel est l’objectif final de toutes ces tâches ?",
  "Quel impact positif cela a-t-il de fabriquer nos propre bouquets ?",
  "Quel outil peut être utilisé pour nettoyer le local ?",
  "Quel outil utilise-t-on pour la remettre en stock ?",
  "Quel produit agit rapidement au démarrage d’une piscine ?",
  "Quel produit est conseillé lorsque l’eau est trouble ?",
  "Quel produit est utilisé pour le démarrage de la piscine ?",
  "Quel produit stabilise les paramètres sur le long terme ?",
  "Quel rythme suit le secteur fleurs artificielles ?",
  "Quel sentiment la vidéo cherche-t-elle à transmettre aux futurs collègues ?",
  "Quel type de roues équipent leurs produits ?",
  "Quelle action faut-il faire immédiatement après avoir écrit la remarque ?",
  "Quelle colonne indique la quantité physique réelle en magasin ?",
  "Quelle est la première tâche de la journée ?",
  "Quelle fleur est mise en avant d’août à octobre ?",
  "Quelle fleur est mise à l’honneur de janvier à mai ?",
  "Quelle icône doit-on cliquer sur le bureau de l'ordinateur pour ouvrir la base de données ?",
  "Quelle nouvelle date est inscrite dans l’exemple ?",
  "Quelle plante domine d’octobre à décembre ?",
  "Quelle plante est appréciée pour l’extérieur ?",
  "Quelle plante est citée parmi les variétés proposées ?",
  "Quelle réduction maximale peut accorder un responsable de rayon seul ?",
  "Quelle réduction maximale un responsable de rayon peut-il accorder seul ?",
  "Quelle saison correspond aux chrysanthèmes ?",
  "Quelle tâche essentielle suit-elle chaque matin ?",
  "Quelles fleurs dominent de mai à août ?",
  "Quelles plantes dominent d’octobre à décembre ?",
  "Qui apprécie particulièrement les carrés végétaux ?",
  "Qui est responsable du secteur fleurs artificielles ?",
  "Qui fait le remplissage du “rouge en vert” ?",
  "Qui met les bouquets en valeur ?",
  "Qui réalise les compositions ?",
  "Si un article n'a pas de code-barres, il faut :",
  "Sur quel logiciel peut-on rechercher des informations sur un article chez Famiflora ?",
  "Travailler en équipe c’est :",
  "Une bonne attitude c’est :",
  "À quelle condition une réduction peut-elle aller jusqu'à 50% ?",
  "À quoi servent les carrés végétaux ?",
  // ============================================================
  // 3e VAGUE — repérées en relisant les questions gardées.
  // ============================================================
  // Doublons quasi mot pour mot d'une autre question déjà présente : les voir
  // toutes les deux dans le même quiz donne l'impression d'un bug.
  "Quel est le rôle du chlore dans la piscine ?",       // = « Quel est le rôle principal du chlore ? »
  "Quel est l’objectif principal du travail Mix ?",     // = « Quel est le rôle principal du secteur Mix ? »
  // Réponse liée à une opération commerciale passée (les soldes de Pâques) :
  // indevinable, et fausse dès l'année suivante.
  "Pourquoi une remise de 20 % est-elle appliquée ?",
];
function estQuestionExclue($q) {
  global $QUESTIONS_EXCLUES;
  $n = normaliseTexteQuestion($q);
  foreach ($QUESTIONS_EXCLUES as $ref) {
    if ($n === normaliseTexteQuestion($ref)) { return true; }
  }
  return false;
}

/**
 * 🧭 QUESTIONS REFORMULÉES — leur donner le contexte qui leur manque.
 *
 * Dans la base Famiformation, ces questions suivaient un titre de chapitre :
 * « Quelle huile utiliser ? » venait après « La plancha ». Sorties de là et
 * tirées au hasard dans un quiz, elles deviennent indevinables — on ne sait
 * même pas de quoi on parle. D'autres sont des fragments de phrase
 * (« Les pots et les plantes sont vendus : ») ou contiennent un « cela » qui
 * ne renvoie à rien.
 *
 * On ne touche PAS à la base : on remplace juste l'énoncé au moment de la
 * réinstallation. Clé = le texte d'origine, valeur = [FR reformulé, NL].
 * La reformulation ne change jamais le SENS ni la bonne réponse — elle ajoute
 * seulement ce qu'un titre de chapitre disait à la place.
 *
 * ⚠️ La reformulation s'applique APRÈS les tests d'exclusion et de favorite,
 * qui portent donc toujours sur le texte d'origine.
 */
$QUESTIONS_RECONTEXTUALISEES = [
  // — Barbecue et plancha : on disait « la plancha », plus haut dans la page.
  "Il faut vider le récupérateur de graisse :"
    => ["Sur un barbecue, à quelle fréquence faut-il vider le récupérateur de graisse ?",
        "Hoe vaak moet je de vetopvangbak van een barbecue leegmaken?"],
  "Risque si on ne vide pas les graisses :"
    => ["Que risque-t-on si on ne vide jamais le bac à graisse d'un barbecue ?",
        "Wat riskeer je als je de vetbak van een barbecue nooit leegmaakt?"],
  "Quelle huile utiliser ?"
    => ["Quelle huile convient le mieux pour cuisiner sur une plancha ?",
        "Welke olie gebruik je het best om op een plancha te koken?"],
  "Quel bois éviter ?"
    => ["Quel bois faut-il éviter de brûler dans un barbecue ou un brasero ?",
        "Welk hout verbrand je beter niet in een barbecue of vuurkorf?"],
  "Quel goût cela donne-t-il à la nourriture ?"
    => ["Sur un barbecue, la graisse qui retombe sur les protecteurs de brûleurs donne quel goût aux aliments ?",
        "Welke smaak geeft het vet dat op de branderbeschermers valt aan het eten?"],
  "Quand verse-t-on le Coca Zero pour nettoyer ?"
    => ["Pour nettoyer une plancha au Coca Zero, à quel moment le verse-t-on ?",
        "Wanneer giet je de Coca Zero om een plancha schoon te maken?"],
  "Pourquoi ne pas mettre les grilles au lave-vaisselle ?"
    => ["Pourquoi ne faut-il pas mettre les grilles d'un barbecue au lave-vaisselle ?",
        "Waarom mogen barbecueroosters niet in de vaatwasser?"],
  "Quel matériau est plus résistant que l’acier pour les plaques ?"
    => ["Pour les plaques de cuisson d'un barbecue, quel matériau est plus résistant que l'acier ?",
        "Welk materiaal is voor bakplaten van een barbecue sterker dan staal?"],

  // — Caisse : toutes ces questions parlaient de la caisse, jamais dit.
  "Que faire face à un client qui à plusieurs sac de course fermé posé sur le Chariot ?"
    => ["En caisse, un client a plusieurs sacs de courses fermés posés sur son chariot. Que faire ?",
        "Aan de kassa heeft een klant meerdere gesloten boodschappentassen op zijn kar. Wat doe je?"],
  "Que faire si un article passe sans bip du scanner ?"
    => ["En caisse, que faire si un article passe sans que le scanner bipe ?",
        "Wat doe je aan de kassa als een artikel passeert zonder piep van de scanner?"],
  "Pourquoi regarder à la fois le tapis et le caddie ?"
    => ["En caisse, pourquoi faut-il regarder à la fois le tapis et le caddie ?",
        "Waarom kijk je aan de kassa zowel naar de band als naar de winkelkar?"],
  "Un petit article est coincé dans un gros. Que faire ?"
    => ["En caisse, un petit article est coincé dans un plus gros. Que faire ?",
        "Aan de kassa zit een klein artikel vastgeklemd in een groter. Wat doe je?"],
  "Pourquoi manipuler certains produits avant scan ?"
    => ["En caisse, pourquoi faut-il manipuler certains produits avant de les scanner ?",
        "Waarom neem je aan de kassa sommige producten eerst vast voor je ze scant?"],
  "Que faire si le TPE est lent ?"
    => ["En caisse, que faire si le terminal de paiement (TPE) est lent ?",
        "Wat doe je aan de kassa als de betaalterminal traag is?"],
  "Quel comportement peut évoquer une tentative de fraude ?"
    => ["En caisse, quel comportement d'un client peut évoquer une tentative de fraude ?",
        "Welk gedrag van een klant kan aan de kassa op fraude wijzen?"],
  "Le ticket ne s’imprime pas, que faire ?"
    => ["En caisse, le ticket ne s'imprime pas : que faut-il vérifier en premier ?",
        "Het kasticket print niet: wat controleer je eerst?"],
  "Dans quel ordre  faut-il scanner les articles ?"
    => ["En caisse, dans quel ordre faut-il scanner les articles ?",
        "In welke volgorde scan je de artikelen aan de kassa?"],
  "Les pots et les plantes sont vendus :"
    => ["En caisse, comment les pots et les plantes sont-ils vendus ?",
        "Hoe worden potten en planten aan de kassa verkocht?"],
  "Via l'application , le client peut :"
    => ["Avec l'application Famiflora, que peut faire le client en magasin ?",
        "Wat kan de klant in de winkel doen met de Famiflora-app?"],
  "Comment faut-il procéder pour les articles lourds restés dans le caddie ?"
    => ["En caisse, comment procéder avec les articles lourds restés dans le caddie ?",
        "Hoe ga je aan de kassa om met zware artikelen die in de kar blijven?"],
  "Que faut-il faire en cas d'article empilé ?"
    => ["En caisse, que faire quand des articles identiques sont empilés les uns dans les autres ?",
        "Wat doe je aan de kassa als identieke artikelen in elkaar gestapeld zitten?"],
  "Que faut-il vérifier pour un bac de bières ?"
    => ["En caisse, que faut-il vérifier avant de scanner un bac de bières ?",
        "Wat controleer je aan de kassa voor je een bak bier scant?"],

  // — Piscine : le chapitre s'appelait « L'entretien de l'eau ».
  "Quel problème peut poser l’eau de puits ?"
    => ["Pour remplir une piscine, quel problème peut poser l'eau de puits ?",
        "Welk probleem kan putwater geven bij het vullen van een zwembad?"],
  "Combien de temps faut-il attendre après avoir ajouté un produit avant de refaire une correction ?"
    => ["Dans une piscine, combien de temps attendre après avoir ajouté un produit avant de corriger à nouveau ?",
        "Hoe lang wacht je in een zwembad na een product voor je opnieuw bijstuurt?"],
  "Quel est le rôle principal du chlore ?"
    => ["Dans une piscine, quel est le rôle principal du chlore ?",
        "Wat is de hoofdrol van chloor in een zwembad?"],
  "Quels paramètres doivent être corrects avant d’utiliser certains produits ?"
    => ["Dans une piscine, quels paramètres doivent être corrects avant d'utiliser les autres produits ?",
        "Welke waarden moeten in een zwembad juist staan voor je andere producten gebruikt?"],
  "Quel est le rôle du floculant ?"
    => ["Dans une piscine, quel est le rôle du floculant ?",
        "Wat doet een vlokmiddel in een zwembad?"],
  "Pourquoi régler les paramètres avant d’ajouter certains produits ?"
    => ["Dans une piscine, pourquoi régler le pH et l'alcalinité avant d'ajouter d'autres produits ?",
        "Waarom stel je in een zwembad eerst pH en alkaliteit juist voor je andere producten toevoegt?"],

  // — Animalerie : le support parlait des rongeurs, la question ne le dit plus.
  "Quels sont les animaux ayant besoin de foin dans leur alimentation ?"
    => ["Au rayon animalerie, quels animaux ont besoin de foin dans leur alimentation ?",
        "Welke dieren in de dierenafdeling hebben hooi nodig in hun voeding?"],
  "Qui a besoin de terre a bain (sable) ?"
    => ["Au rayon animalerie, quels rongeurs ont besoin d'une terre à bain (sable) ?",
        "Welke knaagdieren hebben badzand nodig?"],
  "A quoi sert l'intestinet ? A qui peut-on en donner ?"
    => ["Au rayon animalerie, à quoi sert l'Intestinet et à qui peut-on en donner ?",
        "Waarvoor dient Intestinet en aan wie mag je het geven?"],
  "S'il n'y a pas de foin, que risque l'animal ?"
    => ["Chez un lapin ou un cochon d'Inde, que risque l'animal s'il n'a pas de foin ?",
        "Wat riskeert een konijn of cavia zonder hooi?"],

  // — Règlement et vie au travail.
  "Quelle est la bonne attitude à avoir ?"
    => ["Chez Famiflora, quelle attitude générale attend-on de chaque collaborateur ?",
        "Welke houding verwacht Famiflora van elke medewerker?"],
  "Quel est le bon code vestimentaire à avoir ?"
    => ["Quelle est la tenue de travail attendue chez Famiflora ?",
        "Welke werkkledij wordt bij Famiflora verwacht?"],
  "Combien y a t-il de valeurs dans l'entreprise ?"
    => ["Combien de valeurs Famiflora défend-elle ?",
        "Hoeveel waarden verdedigt Famiflora?"],
  "Ou dois je me présenter quand je commence mon service"
    => ["Chez Famiflora, où dois-je me présenter quand je commence mon service ?",
        "Waar moet ik me bij Famiflora aanmelden als mijn dienst begint?"],
  "Que faut-il faire en cas d’évacuation ?"
    => ["Que faut-il faire en cas d'évacuation du magasin ?",
        "Wat moet je doen bij een evacuatie van de winkel?"],
  "Comment doit être ton attitude en équipe ?"
    => ["Quelle attitude attend-on de chacun au sein de l'équipe ?",
        "Welke houding verwacht men van iedereen binnen het team?"],
  "Que faire avant de quitter ton poste à la fin de ta tâche ?"
    => ["Que faut-il faire avant de quitter son poste, une fois sa tâche terminée ?",
        "Wat doe je voor je je post verlaat als je taak klaar is?"],
  "Que faire en priorité lorsqu’on voit un produit mal placé ?"
    => ["En magasin, que faire en priorité quand on voit un produit rangé au mauvais endroit ?",
        "Wat doe je in de winkel eerst als een product op de verkeerde plaats staat?"],
  "Quel est le rôle principal du secteur Mix ?"
    => ["Chez Famiflora, quel est le rôle principal du secteur Mix ?",
        "Wat is bij Famiflora de hoofdtaak van de afdeling Mix?"],

  // — Piscine (suite) : l'alcalinité de QUOI ? Le mot « piscine » manquait.
  "Que se passe-t-il si l’alcalinité est trop haute ?"
    => ["Dans l'eau d'une piscine, que se passe-t-il si l'alcalinité est trop haute ?",
        "Wat gebeurt er als de alkaliteit van het zwembadwater te hoog is?"],
  "Que faut-il faire si l’alcalinité est trop basse ?"
    => ["Dans l'eau d'une piscine, que faire si l'alcalinité est trop basse ?",
        "Wat doe je als de alkaliteit van het zwembadwater te laag is?"],

  // — Animalerie (suite) : fautes de français de la base, corrigées au passage.
  // « Quel est la taille » → « Quelle est la taille ».
  "Quel est la taille minimum de cage pour les hamsters ?"
    => ["Quelle est la taille minimum de cage pour un hamster ?",
        "Wat is de minimale kooigrootte voor een hamster?"],
  "Quel est la taille minimum de cage pour les gerbilles ?"
    => ["Quelle est la taille minimum de cage pour des gerbilles ?",
        "Wat is de minimale kooigrootte voor gerbils?"],
  "Quel est la taille minimum de cage pour les lapins ?"
    => ["Quelle est la taille minimum de cage pour un lapin ?",
        "Wat is de minimale kooigrootte voor een konijn?"],
  "Quel est la taille minimum de cage pour chinchillas ?"
    => ["Quelle est la taille minimum de cage pour un chinchilla ?",
        "Wat is de minimale kooigrootte voor een chinchilla?"],
  "Quels fruits ou legumes frais sont bons pour les chinchillas ?"
    => ["Quels fruits ou légumes frais peut-on donner à un chinchilla ?",
        "Welke verse groenten of fruit mag een chinchilla krijgen?"],
  "Quelle vitamine faut-il pour les cochons d'inde ? Quels sont les risques s'ils n'en ont pas ?"
    => ["Quelle vitamine est indispensable au cochon d'Inde, et que risque-t-il sans elle ?",
        "Welke vitamine heeft een cavia absoluut nodig, en wat riskeert hij zonder?"],
  "Quelle règle faut-il retenir pour les hamsters ?"
    => ["Au rayon animalerie, quelle règle faut-il retenir pour loger les hamsters ?",
        "Welke regel geldt in de dierenafdeling voor het huisvesten van hamsters?"],
  "Les gerbilles ont besoin de hauteur ou de profondeur ?"
    => ["Pour leur cage, les gerbilles ont-elles besoin de hauteur ou de profondeur ?",
        "Hebben gerbils in hun kooi hoogte of diepte nodig?"],

  // — Jardin.
  "Comment reconnaître une attaque de cochenilles farineuses ?"
    => ["Sur une plante d'intérieur, comment reconnaître une attaque de cochenilles farineuses ?",
        "Hoe herken je wolluis op een kamerplant?"],
  "Quel outil permet de retirer le surplus d'herbe sans se baisser ?"
    => ["Au jardin, quel outil permet de retirer les herbes indésirables sans se baisser ?",
        "Welk tuingereedschap haalt onkruid weg zonder te bukken?"],
];
/**
 * ✍️ CORRECTION ORTHOGRAPHIQUE au moment de la réinstallation.
 *
 * Une partie des questions de la base Famiformation a été saisie SANS AUCUN
 * ACCENT — surtout le bloc animalerie : « necessaire », « deja », « male »,
 * « poussiereux », « a plusieurs ». Ça se voit tout de suite dans un quiz
 * affiché sur grand écran, et ça ne se corrige pas avec la reformulation, qui
 * ne remplace que l'énoncé : le plus gros des fautes est dans les RÉPONSES.
 *
 * Deux niveaux, volontairement prudents :
 *   • des MOTS dont la forme non accentuée n'existe pas en français (« deja »,
 *     « male », « age »…) : remplaçables sans risque de contresens ;
 *   • des EXPRESSIONS, pour tout ce qui est ambigu. « a » peut être le verbe
 *     avoir (« un client a plusieurs sacs ») ou la préposition à (« vivre a
 *     plusieurs ») : remplacer le mot seul casserait des phrases correctes,
 *     alors on ne corrige que des tournures entières.
 */
$MOTS_ACCENTUES = [
  'necessaire' => 'nécessaire', 'deja' => 'déjà', 'developpe' => 'développe',
  'poussiereux' => 'poussiéreux', 'diarrhee' => 'diarrhée', 'arret' => 'arrêt',
  'metre' => 'mètre', 'metres' => 'mètres', 'centimetres' => 'centimètres',
  'probleme' => 'problème', 'maturite' => 'maturité', 'considere' => 'considéré',
  'crepusculaire' => 'crépusculaire', 'ete' => 'été', 'meme' => 'même',
  'facon' => 'façon', 'precaution' => 'précaution', 'sucrees' => 'sucrées',
  'diabetique' => 'diabétique', 'quantite' => 'quantité', 'voliere' => 'volière',
  'amenagee' => 'aménagée', 'etage' => 'étage', 'male' => 'mâle', 'males' => 'mâles',
  'sterilise' => 'stérilisé', 'age' => 'âge', 'controler' => 'contrôler',
  'controle' => 'contrôle', 'separemment' => 'séparément', 'legume' => 'légume',
  'legumes' => 'légumes', 'securite' => 'sécurité', 'proprete' => 'propreté',
  'entrainer' => 'entraîner', 'entraine' => 'entraîne',
];
/**
 * ⚠️ Les clés sont écrites SANS ACCENT, telles qu'elles sortent de la base :
 * les expressions sont traitées AVANT la table des mots. Écrire « arrive a
 * maturité » ici ne correspondrait à rien, puisque « maturite » n'est pas
 * encore accentué à ce moment-là.
 */
$EXPRESSIONS_CORRIGEES = [
  // « a » préposition. Le mot seul est ambigu — c'est aussi le verbe avoir
  // (« un client a plusieurs sacs ») — donc on ne corrige que des tournures.
  'vivre a plusieurs'      => 'vivre à plusieurs',
  "j'ai a la maison"       => "j'ai à la maison",
  'deja a la maison'       => 'déjà à la maison',
  'a partir de'            => 'à partir de',
  'arrive a maturite'      => 'arrive à maturité',
  '3 a 5 mois'             => '3 à 5 mois',
  'a personne, trop'       => 'à personne, trop',
  'a quel animal'          => 'à quel animal',
  'a combien'              => 'à combien',
  'donner a tout le monde' => 'donner à tout le monde',
  'sert a faire dormir'    => 'sert à faire dormir',
  'convient a tous'        => 'convient à tous',
  'convient a des'         => 'convient à des',
  'sucrees a mon'          => 'sucrées à mon',
  'terre a bain'           => 'terre à bain',
  // Les deux formes : dans la base, ce texte est déjà accentué sur « unité »
  // mais pas sur le « à ». Une seule clé serait passée à côté.
  "a l'unite"              => "à l'unité",
  "a l'unité"              => "à l'unité",
  // « stresse » est aussi une forme correcte du verbe (« le changement la
  // stresse ») : on ne corrige donc que ce cas précis, où c'est un participe.
  'est fort stresse'       => 'est fort stressé',
  // Orthographe et accords.
  "cochon d'inde"          => "cochon d'Inde",
  "cochons d'inde"         => "cochons d'Inde",
  'aux cochon'             => 'aux cochons',
];
/**
 * Corrige un texte français (énoncé ou proposition).
 *
 * Trois précautions apprises en le testant sur les 2 000 textes du réservoir :
 *   • les espaces doubles sont supprimés EN PREMIER, sinon « A  l'unité »
 *     n'est reconnu par aucune règle ;
 *   • les remplacements se font avec des LIMITES DE MOT. Sans elles,
 *     « aux cochon » → « aux cochons » se rappliquait à son propre résultat
 *     et produisait « aux cochonss » ;
 *   • la majuscule initiale est conservée, sinon « A partir de » en début de
 *     phrase devenait « à partir de ».
 */
function corrigeOrthographe($t) {
  global $MOTS_ACCENTUES, $EXPRESSIONS_CORRIGEES;
  $t = preg_replace('/ {2,}/', ' ', (string) $t);
  // Remplacement qui garde la majuscule du texte d'origine.
  $remplace = static function ($avant, $apres, $texte) {
    return preg_replace_callback('/\b' . preg_quote($avant, '/') . '\b/iu',
      static function ($m) use ($apres) {
        $premiere = mb_substr($m[0], 0, 1);
        return (mb_strtoupper($premiere) === $premiere && mb_strtolower($premiere) !== $premiere)
          ? mb_strtoupper(mb_substr($apres, 0, 1)) . mb_substr($apres, 1)
          : $apres;
      }, $texte);
  };
  // Les expressions d'abord (elles portent sur des mots encore non accentués).
  foreach ($EXPRESSIONS_CORRIGEES as $avant => $apres) { $t = $remplace($avant, $apres, $t); }
  foreach ($MOTS_ACCENTUES as $avant => $apres)        { $t = $remplace($avant, $apres, $t); }
  return $t;
}

/** Reformulation d'une question, ou null si elle n'en a pas. */
function questionRecontextualisee($q) {
  global $QUESTIONS_RECONTEXTUALISEES;
  static $index = null;
  if ($index === null) {
    $index = [];
    foreach ($QUESTIONS_RECONTEXTUALISEES as $avant => $apres) {
      $index[normaliseTexteQuestion($avant)] = $apres;
    }
  }
  return $index[normaliseTexteQuestion($q)] ?? null;
}
$CODE_GRAINES = 10;   // graines par code bonus (comptent dans le classement)
$MAX_CODES    = 2;    // combien de codes une même personne peut cumuler

// ✉️ Combien de temps le lien « Définir mon mot de passe » reste valable pour
// quelqu'un qui s'inscrit depuis le quiz. 72 h (le défaut de l'app) ne suffit
// pas : on doit pouvoir s'inscrire une semaine avant l'événement et n'activer
// son compte que le jour J.
$ACTIVATION_HEURES = 30 * 24;

// 📄 GOOGLE FORM de récolte des mails (partagé avec l'accueil pour les tickets
// glace). Quand quelqu'un s'inscrit depuis la borne/le téléphone, on recopie sa
// réponse dans CE formulaire, pour que le résultat soit le même que s'il l'avait
// rempli à la main : l'accueil garde sa feuille habituelle. Les identifiants des
// champs ont été relevés sur le formulaire (Nom, Prénom, e-mail « emailAddress »).
$FORM_ACTIF   = true;
$FORM_URL     = 'https://docs.google.com/forms/d/e/1FAIpQLSfEq4cwc2P9aDQno8z3ftMRiKAgttI9UaH46-3PVMQJY_5Feg/formResponse';
$FORM_CHAMP_NOM    = 'entry.2040091278';
$FORM_CHAMP_PRENOM = 'entry.969078151';
// L'e-mail est le champ « collecte d'adresse » de Google : il se soumet via
// « emailAddress » (pas un entry.XXX).

// Recopie une inscription dans le Google Form. « Au mieux » : si l'envoi échoue,
// on n'interrompt JAMAIS l'inscription (la personne reste créée et reçoit son
// mail). cURL avec un délai court pour ne pas ralentir la borne.
function pousseVersForm($prenom, $nom, $email) {
  global $FORM_ACTIF, $FORM_URL, $FORM_CHAMP_NOM, $FORM_CHAMP_PRENOM;
  if (!$FORM_ACTIF || !function_exists('curl_init')) { return false; }
  $post = http_build_query([
    $FORM_CHAMP_NOM    => $nom,
    $FORM_CHAMP_PRENOM => $prenom,
    'emailAddress'     => $email,
    'fvv' => '1', 'pageHistory' => '0',
  ]);
  $ch = curl_init($FORM_URL);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 6,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,      // sur Railway le bundle CA est présent
    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FamiQuiz/1.0)',
  ]);
  $rep = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $code === 200 && $rep !== false;
}

// 📊 STATISTIQUES D'USAGE PAR ÉCRAN (borne, télé, code, téléphone).
//
// Le client calcule déjà l'écran depuis l'URL (/quiz/mouscron/borne → « borne »)
// et le transmet désormais à chaque appel. On enregistre ici les deux moments
// qui comptent : une inscription et une participation au quiz.
//
// Pourquoi la base et pas les fichiers du quiz : ces chiffres se croisent avec
// utilisateurs et widget_sites (qui travaille où, quel magasin), ce qu'un
// fichier JSON ne permet pas. La page /admin_borne.php les lit directement.
//
// Volontairement NON BLOQUANT, comme lapanneCollecte() : une statistique
// perdue est sans conséquence, une inscription perdue non.

function ecranDe($input) {
  $connus = ['borne', 'tele', 'code', 'user'];
  $e = strtolower(trim((string)($input['ecran'] ?? $_GET['ecran'] ?? '')));
  // Écran inconnu ou absent (ancienne version du client encore en cache sur une
  // borne) : on retient « user » plutôt que de refuser l'enregistrement.
  return in_array($e, $connus, true) ? $e : 'user';
}

function borneEvenement($db, $site, $ecran, $type, $joueur = null, $score = null) {
  if (!($db instanceof PDO)) { return false; }
  try {
    $db->exec(
      "CREATE TABLE IF NOT EXISTS quiz_borne_events (
         id INT AUTO_INCREMENT PRIMARY KEY,
         created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         site VARCHAR(20) NOT NULL,
         ecran VARCHAR(10) NOT NULL,
         type VARCHAR(20) NOT NULL,
         joueur VARCHAR(60) NULL,
         score DECIMAL(6,1) NULL,
         INDEX idx_borne_date (created_at),
         INDEX idx_borne_site (site, ecran)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
    );
    $st = $db->prepare(
      'INSERT INTO quiz_borne_events (site, ecran, type, joueur, score) VALUES (?, ?, ?, ?, ?)'
    );
    return $st->execute([
      (string) $site,
      (string) $ecran,
      (string) $type,
      $joueur !== null ? mb_substr((string) $joueur, 0, 60) : null,
      $score !== null ? (float) $score : null,
    ]);
  } catch (Throwable $e) {
    return false;
  }
}

// 🎟️ CODES RÉCOMPENSE (bons cadeaux).
//
// Le stock, ce sont les 200 bons Lollyland : remplacer-codes-lollyland.sql,
// généré depuis FAMIFORMATION_Bon Lollyland.xlsx. (Les codes de
// Test_Enlyson.xlsx étaient erronés et ont été retirés.) Un code part avec le
// mail « ta récompense est prête » et devient définitivement celui de cette
// personne : UN code par personne, jamais deux.
//
// Le compte de test ne pioche PAS dans ce stock — voir recompenseCodeTest().

function recompenseCodesEnsure($db) {
  if (!($db instanceof PDO)) { return false; }
  try {
    $db->exec(
      "CREATE TABLE IF NOT EXISTS recompense_codes (
         id INT AUTO_INCREMENT PRIMARY KEY,
         code_id VARCHAR(30) NOT NULL,
         barcode VARCHAR(60) NOT NULL,
         attribue_a VARCHAR(80) NULL,
         attribue_nom VARCHAR(120) NULL,
         attribue_email VARCHAR(190) NULL,
         attribue_le DATETIME NULL,
         motif VARCHAR(20) NULL,
         created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_barcode (barcode),
         INDEX idx_attribue (attribue_a),
         INDEX idx_libre (attribue_a, id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
    );
    // Le magasin est ajouté après coup : le stock est commun aux deux, mais
    // savoir d'où part chaque bon aide les RH à s'y retrouver.
    $c = $db->query("SHOW COLUMNS FROM recompense_codes LIKE 'site'");
    if ($c && !$c->fetch()) {
      $db->exec("ALTER TABLE recompense_codes ADD COLUMN site VARCHAR(20) NULL AFTER motif");
    }
    return true;
  } catch (Throwable $e) { return false; }
}

// Le compte de test peut recevoir plusieurs codes : il sert justement à
// vérifier l'envoi. Tous les autres sont limités à un seul.
function estCompteAdminTest($cle) {
  return stripos(trim((string) $cle), 'admin_') === 0;
}

// 🧪 LE BON DE TEST DU COMPTE admin_.
//
// Tester l'envoi ne doit RIEN coûter : chaque bon tiré du stock est un bon en
// moins pour un client, et il n'y en a que 200. Le compte de test reçoit donc
// un code fabriqué à la volée, jamais écrit en base. Conséquences voulues :
//   — le stock ne bouge pas ;
//   — admin_ n'apparaît jamais dans « Récompenses données » ;
//   — le mail est identique à un vrai, code-barres compris, donc le test est
//     fidèle.
// Le préfixe FAMITEST- le rend reconnaissable au premier coup d'œil et la
// caisse le refusera : c'est exactement ce qu'on veut d'un faux bon.
function recompenseCodeTest() {
  return [
    'code_id' => 'test',
    'barcode' => 'FAMITEST-' . strtoupper(bin2hex(random_bytes(4))),
  ];
}

// Le code déjà attribué à quelqu'un, ou null. C'est ce qui bloque un second
// envoi : si la personne a déjà son code, il n'y a plus rien à envoyer.
function recompenseCodeExistant($db, $cle) {
  if (!($db instanceof PDO)) { return null; }
  try {
    $st = $db->prepare('SELECT * FROM recompense_codes WHERE attribue_a = ? ORDER BY id DESC LIMIT 1');
    $st->execute([(string) $cle]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  } catch (Throwable $e) { return null; }
}

// Réserve le plus ancien code libre pour cette personne.
//
// SELECT ... FOR UPDATE dans une transaction : sans ce verrou, deux envois
// lancés en même temps par les RH liraient la même ligne « libre » et
// repartiraient avec le MÊME code. Le verrou fait attendre le second.
function recompenseAttribue($db, $cle, $nom, $email, $motif) {
  global $SITE;
  if (!($db instanceof PDO)) { return null; }
  recompenseCodesEnsure($db);
  try {
    $db->beginTransaction();
    $st = $db->prepare('SELECT * FROM recompense_codes WHERE attribue_a IS NULL ORDER BY id ASC LIMIT 1 FOR UPDATE');
    $st->execute();
    $code = $st->fetch(PDO::FETCH_ASSOC);
    if (!$code) { $db->rollBack(); return null; }   // stock épuisé
    $db->prepare(
      'UPDATE recompense_codes
          SET attribue_a = ?, attribue_nom = ?, attribue_email = ?, attribue_le = NOW(), motif = ?, site = ?
        WHERE id = ?'
    )->execute([(string) $cle, (string) $nom, (string) $email, (string) $motif,
                (string) ($SITE ?? ''), (int) $code['id']]);
    $db->commit();
    return $code;
  } catch (Throwable $e) {
    try { if ($db->inTransaction()) { $db->rollBack(); } } catch (Throwable $e2) {}
    return null;
  }
}

// L'adresse publique de l'image code-barres, pour le mail. Les clients mail
// n'affichent pas de SVG : code-barre.php renvoie un PNG.
function recompenseUrlCodeBarre($barcode) {
  $hote = (string) ($_SERVER['HTTP_HOST'] ?? '');
  // Les envois automatiques n'ont pas de requête HTTP : on retombe sur le
  // domaine public, sinon l'image du mail pointerait dans le vide.
  if ($hote === '' || strpos($hote, 'localhost') !== false) { $hote = 'www.famiformation.com'; }
  return 'https://' . $hote . '/quiz/code-barre.php?c=' . rawurlencode((string) $barcode);
}

// 📧 COLLECTE DES ADRESSES DE LA PANNE, DEPUIS LE QUIZ.
//
// La table lapanne_emails est celle de public/emails/lapanne/_lapanne.php, qui
// fait foi pour le schéma. On ne peut PAS inclure ce fichier ici : il charge
// config.php, et le commentaire de famiDb() explique pourquoi c'est exclu —
// config.php ouvre une session, peut émettre des redirections et injecte du
// HTML, trois choses qui détruiraient une réponse JSON. On refait donc l'insert
// à l'identique, en gardant les mêmes règles (adresse en minuscules, doublon
// toléré grâce à la clé unique).
//
// Volontairement NON BLOQUANT : à ce stade le compte est créé et le mail
// d'activation est parti. Hors de question de faire échouer une inscription
// réussie parce que le tableau RH est momentanément indisponible.
function lapanneCollecte($db, $prenom, $nom, $email) {
  if (!($db instanceof PDO)) { return false; }
  $nom    = trim(preg_replace('/\s+/u', ' ', (string) $nom));
  $prenom = trim(preg_replace('/\s+/u', ' ', (string) $prenom));
  $email  = trim(mb_strtolower((string) $email, 'UTF-8'));
  if ($nom === '' || $prenom === '' || $email === '') { return false; }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return false; }
  if (mb_strlen($nom) > 120 || mb_strlen($prenom) > 120 || mb_strlen($email) > 190) { return false; }
  try {
    // Même création que du côté RH : la toute première inscription peut très
    // bien venir de la borne, avant que quiconque n'ait ouvert la page RH.
    $db->exec(
      "CREATE TABLE IF NOT EXISTS lapanne_emails (
         id INT AUTO_INCREMENT PRIMARY KEY,
         nom VARCHAR(120) NOT NULL,
         prenom VARCHAR(120) NOT NULL,
         email VARCHAR(190) NOT NULL,
         ticket_remis TINYINT(1) NOT NULL DEFAULT 0,
         created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_lapanne_email (email),
         INDEX idx_lapanne_created (created_at)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
    );
    $st = $db->prepare('INSERT INTO lapanne_emails (nom, prenom, email) VALUES (?, ?, ?)');
    $st->execute([$nom, $prenom, $email]);
    return true;
  } catch (Throwable $e) {
    // 23000 = adresse déjà présente : c'est un succès, pas une erreur.
    return (string) $e->getCode() === '23000';
  }
}

// 🔐 IDENTIFIANTS ADMIN ET RH — JAMAIS DANS LE CODE.
//
// Ce dépôt est PUBLIC : tout mot de passe écrit ici est lisible par n'importe
// qui sur GitHub, et le restera dans l'historique même après correction. Les
// mots de passe viennent donc de variables d'environnement Railway, comme
// FORM_FEED_SECRET plus bas :
//
//   QUIZ_ADMIN_PWD = le mot de passe du mode admin du quiz   (obligatoire)
//   QUIZ_RH_PWD    = le mot de passe de la page /quiz/rh     (obligatoire)
//   QUIZ_ADMIN_ID  = l'identifiant admin, « admin » par défaut  (facultatif)
//   QUIZ_RH_ID     = l'identifiant RH, « rh » par défaut        (facultatif)
//
// ⚠️ ON ÉCHOUE FERMÉ : tant que le mot de passe n'est pas défini dans
// l'environnement, l'accès est REFUSÉ. Prévoir une valeur de repli reviendrait
// à remettre un mot de passe dans le code — donc à ne rien avoir corrigé. Les
// identifiants, eux, ne sont pas des secrets : ils gardent un défaut.
function quizEnv($nom, $defaut = '') {
  $v = getenv($nom);
  if ($v === false || $v === '') { $v = $_SERVER[$nom] ?? ''; }
  return (string) ($v !== '' ? $v : $defaut);
}

// 🛡️ Comparaison d'un secret, à temps constant, qui REFUSE TOUJOURS un secret
// attendu vide. Sans ce garde-fou, hash_equals('', '') vaudrait VRAI : le jour
// où la variable d'environnement manque, n'importe qui entrerait en envoyant un
// mot de passe vide. Autrement dit, une simple erreur de configuration ouvrirait
// l'admin en grand — exactement l'inverse de ce qu'on cherche ici.
function secretOk($attendu, $fourni) {
  $attendu = (string) $attendu;
  if ($attendu === '') { return false; }
  return hash_equals($attendu, (string) $fourni);
}

$ADMIN_ID  = quizEnv('QUIZ_ADMIN_ID', 'admin');
$ADMIN_PWD = quizEnv('QUIZ_ADMIN_PWD');            // vide = accès impossible
$ADMIN_PIN = $ADMIN_PWD;   // compat : ancien lien api.php?action=reset&pin=...

// 🎁 Accès RH (page /quiz/rh) : voir/cocher les récompenses remises. Séparé de
// l'admin, pour confier la remise des récompenses aux RH sans donner l'admin.
$RH_ID  = quizEnv('QUIZ_RH_ID', 'rh');
$RH_PWD = quizEnv('QUIZ_RH_PWD');                  // vide = accès impossible

// 📄 FLUX DU FORMULAIRE GOOGLE (onglet « recolte de mail »).
// Le site lit la feuille via un mini-script Google (Apps Script) déployé en
// « application web », qui renvoie l'onglet en JSON, protégé par un secret.
// On configure l'URL et le secret côté serveur uniquement (variables Railway) :
//   FORM_FEED_URL    = https://script.google.com/macros/s/…/exec
//   FORM_FEED_SECRET = un mot de passe long, identique à celui du script
$FORM_FEED_URL    = getenv('FORM_FEED_URL')    ?: ($_SERVER['FORM_FEED_URL']    ?? '');
$FORM_FEED_SECRET = getenv('FORM_FEED_SECRET') ?: ($_SERVER['FORM_FEED_SECRET'] ?? '');

// 📁 OÙ SONT STOCKÉS LES SCORES.
// Sur Railway, le disque du conteneur est EFFACÉ à chaque déploiement : si on
// écrivait dans quiz/data, tout le classement disparaîtrait au prochain push.
// On utilise donc le volume persistant (le même que les uploads du site).
// En local ou sur IONOS, pas de volume : on retombe sur quiz/data, c'est correct.
$vol = getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: ($_SERVER['RAILWAY_VOLUME_MOUNT_PATH'] ?? '');
$dataDir = ($vol && @is_dir($vol)) ? rtrim($vol, "/\\") . '/quiz' : __DIR__ . '/data';
// @ et re-test : deux visiteurs simultanés peuvent tenter de créer le dossier
// en même temps, le perdant recevrait un warning inutile.
if (!is_dir($dataDir)) { @mkdir($dataDir, 0755, true); }
if (!is_dir($dataDir)) {
  http_response_code(500);
  echo json_encode(['error' => 'Dossier de données inaccessible']);
  exit;
}
// 🏬 LES DEUX MAGASINS. Le nom sert à l'affichage et au tag du compte (site_id).
// Toute donnée du jeu (scores, codes, questions, jardin, dates) est rangée dans
// un fichier PROPRE À CHAQUE SITE — voir plus bas, une fois la requête lue.
$SITES = [
  'mouscron' => ['nom' => 'Famiflora Mouscron', 'ville' => 'Mouscron'],
  'lapanne'  => ['nom' => 'Famiflora La Panne', 'ville' => 'La Panne'],
];
$SITE_DEFAUT = 'mouscron';

// Le site d'une requête vient du champ `site` (corps JSON ou ?site=). S'il est
// absent ou farfelu, on retombe sur le site par défaut plutôt que d'échouer :
// mieux vaut un quiz qui tourne qu'une page cassée. En pratique le client envoie
// toujours son site (il le tient de son URL).
function siteDe($input, $sites, $defaut) {
  $s = strtolower(trim((string)($input['site'] ?? $_GET['site'] ?? '')));
  return isset($sites[$s]) ? $s : $defaut;
}

// ⚠️ Les fichiers de données ($scoresFile, etc.) sont fixés juste avant le switch,
// après lecture de la requête, car ils dépendent du site. Ne pas les utiliser avant.

// ⏱ Dates de l'événement, modifiables depuis l'admin (onglet Compte à rebours).
//
// 🏬 LES DÉFAUTS SONT PROPRES À CHAQUE MAGASIN. Les deux sites ne vivent pas le
// même événement : Mouscron a ouvert le 29/07, La Panne ouvre le 14/08 et se
// clôture le 10/09. Ces valeurs ne servent que TANT QU'AUCUNE date n'a été
// saisie dans l'admin pour le site concerné (le fichier config-<site>.json prime
// toujours). Sans cette table, La Panne héritait des dates de Mouscron : son
// compte à rebours visait une échéance déjà passée, et la télé basculait
// directement sur le classement au lieu du décompte.
function ladConfig($fichier, $site = 'mouscron') {
  $parSite = [
    'mouscron' => [
      'lancement' => '2026-07-29T12:30',
      'cloture'   => '2026-08-30T23:59',
      'resultats' => '31 août à 12h30',
    ],
    'lapanne' => [
      'lancement' => '2026-08-14T12:30',
      'cloture'   => '2026-09-10T12:30',
      'resultats' => '10 septembre à 12h30',
    ],
  ];
  $d = $parSite[$site] ?? $parSite['mouscron'];

  $c = is_file($fichier) ? json_decode((string)@file_get_contents($fichier), true) : null;
  if (!is_array($c)) { $c = []; }
  return [
    'lancement' => $c['lancement'] ?? $d['lancement'],
    'cloture'   => $c['cloture']   ?? $d['cloture'],
    'resultats' => $c['resultats'] ?? $d['resultats'],
    // Zones du magasin où des codes ont été cachés (indice affiché à J-1) :
    // liste de { nom, nb }.
    'zones'     => (isset($c['zones']) && is_array($c['zones'])) ? array_values($c['zones']) : [],
    // 🏆 Récompenses des 3 premiers, saisies dans l'admin. Tant que la liste est
    // vide, la télé annonce simplement « des récompenses à gagner ».
    'recompenses' => (isset($c['recompenses']) && is_array($c['recompenses'])) ? array_values($c['recompenses']) : [],
  ];
}

/* ============================================================
   🌼 LE JARDIN COLLECTIF
   Les joueurs dépensent leurs graines (= leurs points, qu'ils gardent au
   classement : planter ne fait PAS reculer au classement) pour poser des
   plantes sur une grille commune. Chaque case ne se plante qu'une fois.
   ============================================================ */

// Catalogue : clé => [emoji, nom affiché, coût en graines].
// Les coûts sont pensés pour un score max d'environ 340 graines :
// un bon joueur plante 3-5 fois, un joueur moyen 2-3 fois.
$PLANTES = [
  'trefle'     => ['emoji' => '🍀', 'nom' => 'Trèfle',        'cout' => 1],
  'brin'       => ['emoji' => '🌱', 'nom' => 'Brin d\'herbe',  'cout' => 1],
  'arbreglace' => ['emoji' => '🎟️', 'nom' => 'Arbre à tickets glace', 'cout' => 5],
  'paquerette' => ['emoji' => '🌼', 'nom' => 'Pâquerette',    'cout' => 20],
  'tulipe'     => ['emoji' => '🌷', 'nom' => 'Tulipe',        'cout' => 35],
  'lavande'    => ['emoji' => '💜', 'nom' => 'Lavande',       'cout' => 50],
  'tournesol'  => ['emoji' => '🌻', 'nom' => 'Tournesol',     'cout' => 80],
  'rosier'     => ['emoji' => '🌹', 'nom' => 'Rosier',        'cout' => 120],
  'arbre'      => ['emoji' => '🌳', 'nom' => 'Petit arbre',   'cout' => 200],
  // 🏆 Les 3 LOTUS : chers, magnifiques, ils scintillent au jardin. Les avoir
  // plantés TOUS LES TROIS (+ jardin plein) rend éligible à la récompense.
  // Coûts calibrés : jardin plein + ces 3 lotus ≈ 3 000 graines → ~15 quiz à 20/20.
  'bronze'     => ['emoji' => '🏵️', 'nom' => 'Lotus de bronze', 'cout' => 500,  'rare' => 'bronze'],
  'argent'     => ['emoji' => '💮', 'nom' => 'Lotus d\'argent',  'cout' => 1000, 'rare' => 'argent'],
  'or'         => ['emoji' => '🪷', 'nom' => 'Lotus d\'or',      'cout' => 1500, 'rare' => 'or'],
];
// 🎁 Éligibilité à la récompense « jardin » : jardin PLEIN + ces 3 lotus plantés.
$LOTUS_REQUIS = ['or', 'argent', 'bronze'];

// Taille de la grille (8 colonnes × 6 lignes = 48 cases).
$JARDIN_CASES = 48;

// 🌿 MINI-JEU « chasse aux mauvaises herbes » : combien rapporte chaque herbe.
// Le serveur ne fait JAMAIS confiance au total envoyé par la page : il recalcule
// les graines avec CETTE table, à partir du nombre d'herbes de chaque sorte, et
// plafonne le gain par partie (anti-triche raisonnable pour un jeu bon enfant).
// Le mini-jeu des herbes ne rapporte que des MIETTES : c'est la voie « pour le
// plaisir ». La vraie voie pour finir le jardin, c'est le quiz du jardin.
$HERBE_GAIN = ['normale' => 1, 'bronze' => 2, 'argent' => 3, 'or' => 5];
$HERBE_MAX_PAR_HERBE = 300;   // borne le nombre d'herbes d'une sorte par partie
$HERBE_MAX_GAIN = 15;         // gain maximum crédité en une partie (des miettes)

// 🎯 QUIZ DU JARDIN (rejouable) : la voie « efficace » pour alimenter le jardin.
// Chaque bonne réponse rapporte des graines de jardin (bonus, PAS le classement).
// Environ 1 800 graines pour finir le jardin (3 lotus + cases) → ~12 quiz.
$QUIZ_JARDIN_PAR_BONNE = 10;   // graines de jardin par bonne réponse
$QUIZ_JARDIN_MAX_BONNES = 20;  // plafond par partie (anti-triche bon enfant)

// 🏷️ LE JUSTE PRIX (2e épreuve). Deux régimes, exactement comme le quiz :
//
//   • La PREMIÈRE partie compte pour le CLASSEMENT. Son score s'ajoute à
//     « score », qui alimente à la fois le podium et le solde du jardin. Une
//     seule fois, jamais rejouable — même règle que le quiz, donc rien de
//     nouveau à expliquer aux joueurs.
//   • Les parties SUIVANTES ne nourrissent que le JARDIN : elles créditent
//     « bonus », plafonné par partie.
//
// Le plafond du rejeu est placé ENTRE la chasse aux herbes (15, des miettes)
// et le quiz du jardin (200, la voie efficace). On gagne donc nettement plus
// qu'en tapant des herbes, mais remplir les ~1 800 graines du jardin
// uniquement au Juste Prix demanderait une trentaine de parties. C'est VOULU :
// le quiz reste la voie courte.
$JUSTEPRIX_MAX_PARTIE = 200;   // score maximum d'une partie (10 manches × 20 pts)
$JUSTEPRIX_REJEU_MAX  = 50;    // graines de jardin créditées par partie rejouée

// 🚦 L'INTERRUPTEUR DE L'ÉPREUVE. À false, aucun score n'est enregistré, même
// si quelqu'un connaît l'adresse directe de /quiz/justeprix/ — cacher la tuile
// ne suffit pas, l'API doit refuser elle aussi.
// ⚠️ À basculer EN MÊME TEMPS que « pret » sur la ligne justeprix de PROGRAMME,
// dans quiz/index.html : l'un ferme la porte, l'autre cache le bouton.
$JUSTEPRIX_OUVERT = false;

/**
 * Solde de graines DISPONIBLES pour planter :
 *   récoltées au quiz (score) + gagnées au mini-jeu (bonus) − déjà dépensées.
 * Le « bonus » n'entre PAS dans le classement (score) : le classement, et donc
 * les prix, restent basés sur le quiz uniquement.
 */
function soldeDe($p) {
  // Le score du quiz est un nombre à virgule (bonus rapidité continu) : float.
  $solde = max(0, round(floatval($p['score'] ?? 0) + intval($p['bonus'] ?? 0) - intval($p['depensees'] ?? 0), 1));
  // 🧪 Compte de test (préview) : graines quasi illimitées (plancher 10000) pour
  // pouvoir explorer/remplir tout le jardin sans jamais être bloqué. Exclu du classement.
  if (estCompteTest($p)) { $solde = max($solde, 10000); }
  return $solde;
}

// ❓ QUESTIONS PAR DÉFAUT (secours). Elles ne servent QUE si aucune question n'a
// encore été chargée pour le magasin. Dès que tu cliques « Charger toutes les
// questions » (ou que tu enregistres depuis /quiz/admin), c'est questions.json
// qui fait foi et cette liste n'est plus jamais consultée. Ce sont de vraies
// questions de jardinage/plantes, pour ne jamais laisser un joueur devant un
// placeholder même en cas d'incident.
$QUESTIONS_DEFAUT = [
  ['q' => "En quelle année Famiflora a-t-elle été créée ?", 'options' => ["2012", "2005", "2018", "1999"], 'correct' => 0, 'theme' => 'entreprise'],
  ['q' => "Que faut-il donner à une plante pour qu'elle pousse ?", 'options' => ["De l'eau et de la lumière", "Uniquement de l'ombre", "Du sel", "Rien du tout"], 'correct' => 0, 'theme' => 'culture'],
  ['q' => "À quelle saison plante-t-on généralement les bulbes de tulipes ?", 'options' => ["À l'automne", "En plein été", "En hiver sous la neige", "Elles ne se plantent pas"], 'correct' => 0, 'theme' => 'culture'],
  ['q' => "Un cactus a besoin…", 'options' => ["De très peu d'eau", "D'un arrosage quotidien", "D'être immergé", "De rester dans le noir"], 'correct' => 0, 'theme' => 'culture'],
  ['q' => "À quoi servent les abeilles au jardin ?", 'options' => ["À polliniser les fleurs", "À manger les fruits", "À tondre la pelouse", "À rien d'utile"], 'correct' => 0, 'theme' => 'culture'],
];

/** Nettoie une question venant du navigateur (on ne fait jamais confiance à l'envoi). */
function nettoieQuestion($item) {
  $q = trim((string)($item['q'] ?? ''));
  // 🌍 Bilingue : on garde une traduction NL alignée par index avec les options FR.
  // Une case NL vide = pas encore traduite → le rendu retombe sur le FR.
  $qNl  = trim((string)($item['q_nl'] ?? ''));
  $rawFr = (array)($item['options'] ?? []);
  $rawNl = (array)($item['options_nl'] ?? []);
  $opts = [];
  $optsNl = [];
  foreach ($rawFr as $i => $o) {
    $o = trim((string)$o);
    if ($o === '') { continue; }                 // on saute la même position en NL pour rester alignés
    $opts[] = mb_substr($o, 0, 120);
    $n = trim((string)($rawNl[$i] ?? ''));
    $optsNl[] = ($n !== '') ? mb_substr($n, 0, 120) : '';
  }
  $correct = (int)($item['correct'] ?? 0);
  if ($q === '' || count($opts) < 2) { return null; }          // inutilisable
  if ($correct < 0 || $correct >= count($opts)) { $correct = 0; } // index hors liste
  // 🎯 Thème de la question : sert à composer le quiz (10 entreprise, 5 culture
  // générale, 5 « fun »). Valeur inconnue ou absente → « entreprise ».
  $theme = strtolower(trim((string)($item['theme'] ?? '')));
  if ($theme === 'anecdote') { $theme = 'fun'; }                  // ancien nom → nouveau
  if (!in_array($theme, ['entreprise', 'culture', 'fun'], true)) { $theme = 'entreprise'; }
  // ⭐ Favorite : question qui apparaîtra plus souvent. Ne concerne QUE entreprise
  // et fun (la culture n'a pas de favoris). On la conserve à l'enregistrement.
  $fav = !empty($item['fav']) && in_array($theme, ['entreprise', 'fun'], true);
  $out = ['q' => mb_substr($q, 0, 300), 'options' => $opts, 'correct' => $correct, 'theme' => $theme, 'fav' => $fav];
  // On n'ajoute les champs NL que s'il existe vraiment une traduction (fichiers plus légers).
  $aDuNl = ($qNl !== '') || count(array_filter($optsNl, static function ($x) { return $x !== ''; })) > 0;
  if ($aDuNl) {
    $out['q_nl'] = mb_substr($qNl, 0, 300);
    $out['options_nl'] = $optsNl;
  }
  return $out;
}

/** Les questions en vigueur (fichier si présent, sinon la liste par défaut). */
function lesQuestions($fichier, $defaut) {
  $d = readJson($fichier);
  $out = [];
  foreach ($d as $item) {
    $c = nettoieQuestion($item);
    if ($c) { $out[] = $c; }
  }
  return $out ?: $defaut;
}

/**
 * Porte d'entrée des actions d'administration.
 * Les identifiants sont revérifiés À CHAQUE appel : le « mode admin » du
 * navigateur n'est qu'un affichage, il ne protège rien. Sans cette vérification
 * ici, n'importe qui pourrait appeler l'action directement et vider le classement.
 */
function exigeAdmin($input) {
  global $ADMIN_ID, $ADMIN_PWD;
  $id  = trim($input['id'] ?? '');
  $pwd = (string)($input['pwd'] ?? '');
  if (!hash_equals($ADMIN_ID, $id) || !secretOk($ADMIN_PWD, $pwd)) {
    http_response_code(401);
    echo json_encode(['error' => 'Acces refuse']);
    exit;
  }
}
// 🎁 Garde de la page RH. L'admin a AUSSI le droit (pratique pour toi).
function exigeRh($input) {
  global $RH_ID, $RH_PWD, $ADMIN_ID, $ADMIN_PWD;
  $id  = trim($input['id'] ?? '');
  $pwd = (string)($input['pwd'] ?? '');
  $okRh    = hash_equals($RH_ID, $id) && secretOk($RH_PWD, $pwd);
  $okAdmin = hash_equals($ADMIN_ID, $id) && secretOk($ADMIN_PWD, $pwd);
  if (!$okRh && !$okAdmin) {
    http_response_code(401);
    echo json_encode(['error' => 'Acces refuse']);
    exit;
  }
}

/**
 * LECTURE SEULE (verrou partagé : plusieurs lecteurs en même temps, c'est permis).
 * À n'utiliser que quand on ne compte PAS réécrire derrière.
 */
/**
 * Lit un fichier JSON. Renvoie null si le fichier est absent, vide ou illisible
 * — on distingue « pas de contenu » de « contenu invalide », c'est ce qui permet
 * de basculer sur la sauvegarde plutôt que de repartir de zéro.
 */
function famiLitJsonBrut($file) {
  if (!is_file($file)) return null;
  $c = @file_get_contents($file);
  if ($c === false || trim($c) === '') return null;
  $d = json_decode($c, true);
  return is_array($d) ? $d : null;
}

function readJson($file) {
  $d = famiLitJsonBrut($file);
  if ($d !== null) return $d;
  // ⛑️ FILET DE SÉCURITÉ. Le fichier principal est illisible (coupure en pleine
  // écriture, disque plein…) : on repart de la dernière version saine plutôt que
  // de rendre un classement VIDE. Rendre vide serait le pire : la page l'afficherait
  // comme la vérité, puis le prochain enregistrement l'écrirait par-dessus la
  // sauvegarde. Une donnée qu'on ne peut pas reconstituer serait perdue pour de bon.
  $secours = famiLitJsonBrut($file . '.bak');
  if ($secours !== null) {
    error_log('[quiz] ' . basename($file) . ' illisible : reprise sur la sauvegarde .bak');
    return $secours;
  }
  return [];
}

/**
 * ÉCRITURE ATOMIQUE — le cœur de la protection du classement.
 *
 * L'ancienne façon de faire (ftruncate puis fwrite sur le fichier lui-même)
 * laissait le fichier VIDE entre les deux opérations. Un conteneur remplacé, un
 * plantage ou un manque de mémoire pile à cet instant, et tout le classement du
 * magasin disparaissait.
 *
 * Ici on écrit à côté, on force sur le disque, puis on renomme. rename() est
 * atomique sur le système de fichiers : à tout instant, le fichier final est
 * soit l'ancien complet, soit le nouveau complet. Jamais un entre-deux.
 *
 * @return bool false si RIEN n'a été écrit (l'ancien fichier reste intact).
 */
function famiEcritJsonAtomique($file, $data) {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) {
    error_log('[quiz] encodage JSON impossible pour ' . basename($file) . ' : rien touché');
    return false;   // on ne détruit surtout pas ce qui existe
  }

  $tmp = $file . '.tmp';
  $fp = @fopen($tmp, 'wb');
  if (!$fp) return false;
  $ecrit = @fwrite($fp, $json);
  $ok = ($ecrit !== false && $ecrit === strlen($json));
  if ($ok) {
    fflush($fp);
    // fsync : les octets sont sur le DISQUE, pas seulement dans un cache que
    // l'arrêt du conteneur emporterait.
    if (function_exists('fsync')) { @fsync($fp); }
  }
  fclose($fp);
  if (!$ok) { @unlink($tmp); return false; }

  if (!@rename($tmp, $file)) { @unlink($tmp); return false; }

  // Sauvegarde APRÈS le remplacement, pas avant : elle reflète ainsi le dernier
  // état RÉUSSI, et non l'avant-dernier. Si le fichier principal devient un jour
  // illisible, on repart du classement complet au lieu d'en perdre la dernière
  // écriture. Sans danger : rename() étant atomique, le fichier qu'on copie ici
  // est forcément entier.
  @copy($file, $file . '.bak');
  return true;
}

/**
 * Verrou d'écriture, porté par un fichier DÉDIÉ (.lock) et non par le fichier
 * de données.
 *
 * C'est indispensable depuis l'écriture atomique : rename() remplace le fichier
 * de données, donc un verrou posé dessus resterait accroché à l'ancien fichier,
 * désormais invisible. Deux requêtes simultanées se croiraient alors seules et
 * la seconde écraserait le travail de la première.
 *
 * @return resource|false
 */
function famiPrendVerrou($file) {
  // On tente d'abord le fichier de verrou dédié. S'il ne peut pas être CRÉÉ
  // (droits du dossier, volume restreint…), on se rabat sur le fichier de
  // données lui-même — ce que faisait l'ancien code, et qui marchait.
  //
  // Sans ce repli, un simple fichier .lock impossible à créer fermait TOUT :
  // connexion, jardin, envoi de score. Un verrou imparfait vaut infiniment mieux
  // qu'un service à l'arrêt.
  $fp = @fopen($file . '.lock', 'c');
  if (!$fp) { $fp = @fopen($file, 'c'); }
  if (!$fp) { return false; }
  @flock($fp, LOCK_EX);           // ⬅ attente ici si quelqu'un d'autre écrit
  return $fp;
}

/**
 * Écriture EN PLACE — l'ancienne méthode, gardée comme dernier recours.
 * Moins sûre (le fichier est vide un très court instant), mais elle fonctionne
 * là où le renommage atomique échoue. Mieux vaut ce risque minuscule qu'une
 * donnée perdue parce qu'on a refusé d'écrire.
 */
function famiEcritJsonEnPlace($file, $data) {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) { return false; }
  $fp = @fopen($file, 'c');
  if (!$fp) { return false; }
  @flock($fp, LOCK_EX);
  @ftruncate($fp, 0);
  @rewind($fp);
  $ok = (@fwrite($fp, $json) !== false);
  @fflush($fp);
  @flock($fp, LOCK_UN);
  @fclose($fp);
  return $ok;
}

function famiRendVerrou($fp) {
  if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
}

/**
 * LECTURE + MODIFICATION + ÉCRITURE, sous UN SEUL verrou exclusif.
 *
 * C'est le cœur de la protection contre les accès simultanés : tant que $fn
 * travaille, personne d'autre ne peut ni lire ni écrire ce fichier. On relit
 * DANS le verrou (pas avant), donc $fn voit toujours l'état le plus à jour.
 *
 * $fn reçoit ($data, $write) PAR RÉFÉRENCE : modifie $data et mets $write = true
 * pour que le fichier soit réécrit. Ce que $fn retourne est renvoyé tel quel.
 */
function withLock($file, callable $fn) {
  // ⚠️ ON NE REFUSE JAMAIS DE TRAVAILLER. Pas de verrou obtenu ? On continue
  // sans. Le risque de collision entre deux joueurs simultanés est minime ; le
  // risque de fermer le jeu à tout le monde était, lui, certain.
  $verrou = famiPrendVerrou($file);
  if (!$verrou) { error_log('[quiz] verrou indisponible sur ' . basename($file) . ' : on continue sans'); }

  // readJson() bascule au besoin sur la sauvegarde .bak.
  $data = readJson($file);

  $write = false;
  $reponse = $fn($data, $write);

  if ($write) {
    // Écriture atomique en priorité ; en dernier recours, l'ancienne écriture en
    // place. Ne JAMAIS renvoyer d'erreur ici : le joueur ne doit pas rester
    // coincé parce qu'un renommage a échoué sur ce système de fichiers.
    if (!famiEcritJsonAtomique($file, $data)) {
      error_log('[quiz] ecriture atomique impossible sur ' . basename($file) . ' : repli en place');
      famiEcritJsonEnPlace($file, $data);
    }
  }

  famiRendVerrou($verrou);
  return $reponse;
}

/** Écriture simple (sans lecture préalable) : uniquement pour la remise à zéro. */
function writeJson($file, $data) {
  $verrou = famiPrendVerrou($file);
  if (!$verrou) return false;
  $ok = famiEcritJsonAtomique($file, $data);
  famiRendVerrou($verrou);
  return $ok;
}

function sortBoard(&$board) {
  usort($board, function ($a, $b) {
    // Score DÉCROISSANT, décimales incluses (la rapidité départage). On utilise
    // l'opérateur <=> : un retour flottant serait tronqué en int et perdrait la
    // virgule. En cas d'égalité stricte, le plus rapide (time) passe devant.
    $sa = floatval($a['score'] ?? 0); $sb = floatval($b['score'] ?? 0);
    if ($sa !== $sb) return $sb <=> $sa;
    return intval($a['time'] ?? 0) <=> intval($b['time'] ?? 0);
  });
}

/* ============================================================
   🔗 LES COMPTES VIENNENT DE FAMIFORMATION
   Le quiz ne gère plus ses propres comptes : pour jouer, il faut un compte
   Famiformation (le même que pour se connecter au site). Le quiz tourne dans le
   MÊME conteneur que l'app, donc il lit la même base.

   On ne charge surtout PAS config.php : il ouvre une session, fait des
   redirections (header Location) et injecte du HTML dans la sortie — trois
   choses qui casseraient des réponses JSON. includes/functions.php, lui, se
   suffit à lui-même : famiGetEnv(), sendMail(), sendAccountActivationEmail()...
   ============================================================ */
function famiDb() {
  static $db = null, $deja = false;
  if ($deja) { return $db; }
  $deja = true;
  // Deux dispositions : dans le conteneur, quiz/ est DANS public/ (Dockerfile) ;
  // dans le dépôt, quiz/ et public/ sont côte à côte.
  $lib = null;
  foreach ([__DIR__ . '/../includes/functions.php', __DIR__ . '/../public/includes/functions.php'] as $piste) {
    if (is_file($piste)) { $lib = $piste; break; }
  }
  if ($lib === null) { return null; }
  // Ceinture et bretelles : si ce fichier émettait quoi que ce soit (avertissement,
  // espace avant <?php), ça se collerait dans notre JSON et le joueur verrait une
  // erreur incompréhensible. On avale tout ce qui pourrait sortir.
  ob_start();
  require_once $lib;
  // Liste du personnel : sert à donner d'emblée le bon profil à quelqu'un qui
  // s'inscrit et qui travaille déjà chez Famiflora (voir roleInscription()).
  foreach ([__DIR__ . '/../includes/personnel_liste.php', __DIR__ . '/../public/includes/personnel_liste.php'] as $pl) {
    if (is_file($pl)) { require_once $pl; break; }
  }
  ob_end_clean();
  try {
    // QUIZ_DB_DSN sert aux essais hors ligne (SQLite) ; en production, ce sont
    // les mêmes variables que l'app.
    $dsn = (string) famiGetEnv('QUIZ_DB_DSN', '');
    if ($dsn !== '') {
      $db = new PDO($dsn, (string) famiGetEnv('QUIZ_DB_USER', ''), (string) famiGetEnv('QUIZ_DB_PASS', ''));
    } else {
      $db = new PDO(
        'mysql:host=' . famiGetEnv('DB_HOST', 'localhost') . ';dbname=' . famiGetEnv('DB_NAME', '') . ';charset=utf8mb4',
        (string) famiGetEnv('DB_USER', ''), (string) famiGetEnv('DB_PASSWORD', '')
      );
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $db = null;
  }
  return $db;
}

// 🔐 Le jeton de session du joueur. On ne garde JAMAIS son mot de passe côté
// navigateur : à la connexion on lui remet un jeton signé, qu'il renvoie ensuite.
// Signature HMAC avec un secret tiré une fois pour toutes dans le dossier de
// données — donc rien à stocker par joueur, et un jeton bricolé est rejeté.
function quizSecret() {
  global $dataDir;
  static $secret = null;
  if ($secret !== null) { return $secret; }
  $f = $dataDir . '/secret.txt';
  if (is_file($f)) { $secret = trim((string) @file_get_contents($f)); }
  if (empty($secret)) {
    $secret = bin2hex(random_bytes(32));
    @file_put_contents($f, $secret);
    @chmod($f, 0600);
  }
  return $secret;
}
// Séparateur « | » et identifiant encodé : les identifiants contiennent un POINT
// (prenom.nom), donc découper sur le point casserait la relecture du jeton.
function faitJeton($uid, $identifiant, $jours = 60) {
  $exp = time() + $jours * 86400;
  $corps = ((int) $uid) . '|' . ($exp) . '|' . rawurlencode((string) $identifiant);
  return $corps . '|' . hash_hmac('sha256', $corps, quizSecret());
}
function litJeton($jeton) {
  $p = explode('|', (string) $jeton);
  if (count($p) !== 4) { return null; }
  [$uid, $exp, $ident, $sig] = $p;
  $corps = $uid . '|' . $exp . '|' . $ident;
  if (!hash_equals(hash_hmac('sha256', $corps, quizSecret()), $sig)) { return null; }
  if ((int) $exp < time()) { return null; }
  return ['uid' => (int) $uid, 'identifiant' => rawurldecode($ident)];
}

// 🔐 Un joueur a-t-il le droit d'agir sous le nom `$name` ? Deux preuves possibles :
//   • un JETON de session valide (compte Famiformation) dont l'identifiant = $name ;
//   • sinon, l'ancien code jardinier à 4 chiffres qui correspond à la fiche.
// C'est ce qui empêche quelqu'un de soumettre un score au nom d'un autre : les
// comptes Famiformation n'ont pas de code, seul leur jeton signé les authentifie.
function joueurAutorise($input, $name, $ficheCode) {
  $auth = litJeton($input['jeton'] ?? '');
  if ($auth && mb_strtolower($auth['identifiant']) === mb_strtolower((string) $name)) {
    return true;                                  // jeton Famiformation valide
  }
  $code4 = preg_replace('/\D/', '', (string)($input['code'] ?? ''));
  $ficheCode = (string) $ficheCode;
  return $ficheCode !== '' && $ficheCode === $code4;   // ancien compte pseudo + code
}

// Un identifiant libre au format « PrénomN » : le prénom (1re lettre en majuscule)
// suivi de la 1re lettre du nom en MAJ. Ex. : Jean Dupont → « JeanD ». En cas de
// DOUBLON, on n'ajoute PAS de numéro : on ALLONGE le nom lettre par lettre →
// « JeanDu », « JeanDup », etc., jusqu'à trouver un identifiant libre. (Un numéro
// n'arrive qu'en dernier recours, si même le nom entier est déjà pris.)
// C'est ce que la personne tapera pour se connecter — l'e-mail marche aussi.
// Base d'un identifiant quand on n'a pas forcément le prénom : on prend le
// prénom s'il existe, sinon la PARTIE LOCALE de l'e-mail (avant le @). Comme ça
// on ne retombe JAMAIS sur un « compte » générique dans le mail.
function baseIdFrom($prenom, $email) {
  $prenom = trim((string) $prenom);
  if ($prenom !== '') { return $prenom; }
  $local = strstr((string) $email, '@', true);
  $local = ($local === false) ? '' : trim($local);
  return $local !== '' ? $local : 'membre';
}
// Un identifiant est-il un placeholder à corriger (« Compte… » ou vide) ?
function idEstPlaceholder($id) {
  $id = trim((string) $id);
  return $id === '' || preg_match('/^compte/i', $id) === 1;
}

function identifiantLibre(PDO $db, $prenom, $nom) {
  $sansAccent = function ($s) {
    $s = (string) $s;
    $tr = ['à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
           'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
           'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','œ'=>'oe','æ'=>'ae'];
    return strtr(mb_strtolower($s), $tr);
  };
  // On ne garde que les lettres/chiffres (les espaces, tirets, etc. sautent).
  $p = preg_replace('/[^a-z0-9]+/', '', $sansAccent($prenom));
  $n = preg_replace('/[^a-z0-9]+/', '', $sansAccent($nom));
  if ($p === '') { $p = 'joueur'; }
  $prefixe = ucfirst($p);   // « Jean »
  $stmt = $db->prepare('SELECT COUNT(*) FROM utilisateurs WHERE identifiant = ?');
  $libre = function ($id) use ($stmt) { $stmt->execute([$id]); return (int) $stmt->fetchColumn() === 0; };

  $lenN = strlen($n);
  if ($lenN > 0) {
    // On allonge le nom : JeanD, JeanDu, JeanDup… jusqu'au 1er identifiant libre.
    for ($k = 1; $k <= $lenN; $k++) {
      $essai = substr($prefixe . ucfirst(substr($n, 0, $k)), 0, 40);
      if ($libre($essai)) { return $essai; }
    }
    // Nom entier épuisé et toujours pris → dernier recours : un numéro.
    $complet = substr($prefixe . ucfirst($n), 0, 37);
    for ($i = 2; $i < 500; $i++) { if ($libre($complet . $i)) { return $complet . $i; } }
    return $complet . bin2hex(random_bytes(2));
  }
  // Pas de nom : prénom seul, puis un numéro si déjà pris.
  for ($i = 0; $i < 500; $i++) {
    $essai = $i === 0 ? $prefixe : $prefixe . ($i + 1);
    if ($libre($essai)) { return $essai; }
  }
  return $prefixe . bin2hex(random_bytes(3));
}

// 🎁 Mail « fun » d'invitation à créer son compte AVANT le lancement (envoi groupé).
// Même lien d'activation que d'habitude (set_password.php + jeton), mais habillage
// festif « Noël avant l'heure ». Renvoie true si le mail est parti.
function envoiFunActivation(PDO $db, $userId, $heures = 336) {
  if (!function_exists('issueUserAccountAccessToken') || !function_exists('sendMail') || !function_exists('famiBuildAppUrl')) {
    return false;
  }
  if (function_exists('ensureUserAccountAccessColumns')) { ensureUserAccountAccessColumns($db); }
  $stmt = $db->prepare('SELECT id, identifiant, prenom, nom, email FROM utilisateurs WHERE id = ? LIMIT 1');
  $stmt->execute([(int) $userId]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$u || empty($u['email'])) { return false; }

  // 🔧 Anti « Compte » — DERNIER rempart : si l'identifiant est un placeholder
  // (« Compte… » ou vide), on le régénère ICI, juste avant de construire le mail
  // (prénom si présent, sinon dérivé de l'e-mail). Ainsi le mail n'affiche JAMAIS
  // « Compte », quelle que soit l'origine du compte.
  if (idEstPlaceholder($u['identifiant'] ?? '')) {
    $nouveau = identifiantLibre($db, baseIdFrom($u['prenom'] ?? '', $u['email']), (string) ($u['nom'] ?? ''));
    try { $db->prepare('UPDATE utilisateurs SET identifiant = ? WHERE id = ?')->execute([$nouveau, (int) $u['id']]); } catch (Throwable $e) {}
    $u['identifiant'] = $nouveau;
  }

  $heures = max(1, (int) $heures);
  $token = issueUserAccountAccessToken($db, $u['id'], 'activation', $heures);
  $url   = famiBuildAppUrl('set_password.php', ['token' => $token]);
  // Le prénom remis en forme. Sans prénom on écrivait l'identifiant du compte —
  // « Bonjour marie.durand, » — ce qui ressemble à un mail automatique raté.
  $prenom = prenomAffichable($u['prenom'] ?? '');
  $bonjour = $prenom !== '' ? $prenom : 'à toi';
  $validite = $heures >= 48 ? ((int) round($heures / 24) . ' jours') : ((int) $heures . ' heures');
  $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

  $subject = '🌱 Prends de l\'avance : crée déjà ton compte Famiformation !';
  $body = '<div style="margin:0;padding:32px;background:#eef4ef;font-family:Open Sans,Arial,sans-serif;color:#244230;">'
    . '<div style="max-width:680px;margin:0 auto;background:#fff;border-radius:24px;overflow:hidden;box-shadow:0 18px 38px rgba(27,54,36,.12);">'
    . '<div style="padding:30px 32px;background:linear-gradient(135deg,#2d5a37 0%,#4a7b55 100%);color:#fff;">'
    . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">Famiformation · Famiflora</div>'
    . '<h1 style="margin:10px 0 8px;font-size:28px;line-height:1.2;">🌱 Prends de l\'avance&nbsp;!</h1>'
    . '<p style="margin:0;font-size:15px;line-height:1.6;opacity:.95;">Tu peux déjà créer ton compte Famiformation, avant le grand lancement du 29/07.</p>'
    . '</div>'
    . '<div style="padding:32px;">'
    . '<p style="margin:0 0 16px;font-size:16px;line-height:1.7;">Bonjour ' . $e($bonjour) . ',</p>'
    . '<p style="margin:0 0 16px;font-size:16px;line-height:1.7;">Bonne nouvelle&nbsp;! 🎉 On t\'avait dit que tu recevrais ton lien le 29/07… mais on te l\'envoie <b>en avance</b>. '
    . 'Tu peux <b>dès maintenant créer ton compte Famiformation</b> (choisir ton mot de passe) pour être fin prêt(e) le jour du lancement — le <b>quiz</b> et ton <b>espace jardin</b> t\'attendront&nbsp;! 🌿</p>'
    . '<div style="margin:22px 0;padding:20px;border-radius:18px;background:#f6faf7;border:1px solid #dde9df;">'
    . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6a7d72;margin-bottom:10px;">Ton identifiant</div>'
    . '<p style="margin:0;font-size:16px;"><b>' . $e($u['identifiant']) . '</b></p>'
    . '</div>'
    . '<p style="margin:0 0 22px;"><a href="' . $e($url) . '" style="display:inline-block;padding:14px 24px;border-radius:999px;background:#d6a21a;color:#fff;font-weight:700;text-decoration:none;">🌱 Créer mon compte</a></p>'
    . '<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#3a5443;">Ce lien est valable ' . $e($validite) . '. Une fois connecté(e), tu pourras déjà découvrir Famiformation. 🌿</p>'
    . '<p style="margin:0;font-size:14px;line-height:1.7;color:#617268;">Tu n\'es pas concerné(e) par ce message&nbsp;? Ignore-le simplement. Une question&nbsp;? Écris à admin@famiformation.com.</p>'
    . '</div>'
    . '<div style="padding:18px 32px;background:#f5f8f6;color:#617268;font-size:13px;">🌱 Message envoyé par Famiformation — Famiflora.</div>'
    . '</div></div>';

  return sendMail($u['email'], $subject, $body, true);
}

// 📄 Lit l'onglet « recolte de mail » du formulaire via le script Google.
// Renvoie ['ok'=>true,'lignes'=>[['prenom','nom','email'],…]] ou un tableau
// ['ok'=>false,'reason'=>…]. La liste n'est JAMAIS exposée au navigateur : seul
// le serveur connaît l'URL + le secret.
function litFluxFormulaire() {
  global $FORM_FEED_URL, $FORM_FEED_SECRET;
  if ($FORM_FEED_URL === '' || $FORM_FEED_SECRET === '') {
    return ['ok' => false, 'reason' => 'non_configure'];
  }
  $url = $FORM_FEED_URL . (strpos($FORM_FEED_URL, '?') === false ? '?' : '&')
       . 'secret=' . rawurlencode($FORM_FEED_SECRET);
  $brut = null;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,   // Google redirige vers googleusercontent
      CURLOPT_TIMEOUT        => 20,
      CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $brut = curl_exec($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1]]);
    $brut = @file_get_contents($url, false, $ctx);
  }
  if ($brut === false || $brut === null || $brut === '') {
    return ['ok' => false, 'reason' => 'injoignable'];
  }
  $j = json_decode($brut, true);
  if (!is_array($j) || empty($j['ok']) || !isset($j['lignes']) || !is_array($j['lignes'])) {
    return ['ok' => false, 'reason' => 'reponse_invalide'];
  }
  $out = [];
  foreach ($j['lignes'] as $l) {
    $email = mb_strtolower(trim((string) ($l['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
    $out[] = [
      'prenom' => trim(mb_substr((string) ($l['prenom'] ?? ''), 0, 40)),
      'nom'    => trim(mb_substr((string) ($l['nom'] ?? ''), 0, 60)),
      'email'  => $email,
    ];
  }
  return ['ok' => true, 'lignes' => $out];
}

// 🔑 Clé « prénom|nom » normalisée (minuscule, sans espaces superflus) pour
// comparer deux personnes par leur nom.
function clePrenomNom($prenom, $nom) {
  return mb_strtolower(trim((string) $prenom)) . '|' . mb_strtolower(trim((string) $nom));
}

// 📇 Contenu du formulaire mis en cache court sur disque (l'inscription publique
// peut arriver souvent : on n'appelle donc pas le script Google à chaque fois).
// Renvoie ['emails' => ['x@y'=>true,…], 'noms' => ['prenom|nom'=>true,…]].
// En cas d'échec réseau, on garde l'ancien cache.
function fluxCache($ttl = 300) {
  global $dataDir, $FORM_FEED_URL, $FORM_FEED_SECRET;
  $vide = ['emails' => [], 'noms' => []];
  if ($FORM_FEED_URL === '' || $FORM_FEED_SECRET === '') { return $vide; }
  $cache = $dataDir . '/form-cache.json';
  $lire = function () use ($cache) {
    if (!is_file($cache)) { return null; }
    $c = json_decode((string) @file_get_contents($cache), true);
    return (is_array($c) && isset($c['emails'], $c['noms'])) ? $c : null;
  };
  if (is_file($cache) && (time() - (int) @filemtime($cache) < $ttl)) {
    $c = $lire();
    if ($c !== null) { return $c; }
  }
  $flux = litFluxFormulaire();
  if (!$flux['ok']) { $c = $lire(); return $c !== null ? $c : $vide; }
  $sets = ['emails' => [], 'noms' => []];
  foreach ($flux['lignes'] as $l) {
    $sets['emails'][$l['email']] = true;
    if (trim($l['prenom']) !== '' && trim($l['nom']) !== '') {
      $sets['noms'][clePrenomNom($l['prenom'], $l['nom'])] = true;
    }
  }
  @file_put_contents($cache, json_encode($sets), LOCK_EX);
  return $sets;
}

// 🔒 Interrupteur de l'envoi AUTOMATIQUE (temps réel) à chaque nouvelle réponse
// du formulaire. DÉSACTIVÉ par défaut : aucun mail ne part automatiquement tant
// que l'admin ne l'a pas allumé depuis l'onglet « Envoi groupé ». (Le bouton
// « Envoyer à tous » reste manuel et n'est PAS concerné par cet interrupteur.)
function autoEnvoiActif() {
  global $dataDir;
  $f = $dataDir . '/form-auto.json';
  $c = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
  return is_array($c) && !empty($c['actif']);
}
function autoEnvoiDefinir($actif) {
  global $dataDir;
  @file_put_contents($dataDir . '/form-auto.json', json_encode(['actif' => (bool) $actif]), LOCK_EX);
}

// 👤 Traite UNE personne pour l'envoi groupé : crée le compte si besoin puis
// envoie le mail d'invitation, ou renvoie le lien, ou l'ignore si déjà présente.
// $parNom = true → on considère « déjà dans le site » un compte au même
// prénom+nom (contrôle demandé pour la liste du formulaire) ; sinon par e-mail.
// Renvoie l'un de : cree | renvoye | deja_present | mail_ko | erreur.
// $resendPending : en envoi MANUEL on ré-envoie le lien à un compte encore en
// attente (utile pour relancer). En temps réel (formulaire auto) on met false :
// si l'e-mail existe déjà (ex. personne inscrite à la BORNE, qui a déjà reçu son
// mail), on NE renvoie PAS un 2e mail. Évite le doublon borne + formulaire.
function traiteInscritGroupe(PDO $db, array $p, $siteId, $heures, $parNom = false, $resendPending = true) {
  $email  = mb_strtolower(trim((string) ($p['email'] ?? '')));
  $prenom = trim(mb_substr((string) ($p['prenom'] ?? ''), 0, 40));
  $nom    = trim(mb_substr((string) ($p['nom'] ?? ''), 0, 60));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return 'erreur'; }
  try {
    if (function_exists('ensureUserAccountAccessColumns')) { ensureUserAccountAccessColumns($db); }
    // 1) Déjà présent ? Par prénom+nom (si demandé) OU par e-mail dans tous les cas.
    if ($parNom && $prenom !== '' && $nom !== '') {
      $q = $db->prepare('SELECT id FROM utilisateurs WHERE LOWER(TRIM(prenom)) = ? AND LOWER(TRIM(nom)) = ? LIMIT 1');
      $q->execute([mb_strtolower($prenom), mb_strtolower($nom)]);
      if ($q->fetchColumn() !== false) { return 'deja_present'; }
    }
    $st = $db->prepare('SELECT id, identifiant, mot_de_passe, account_activation_pending FROM utilisateurs WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if ($u) {
      // Compte actif (mot de passe choisi) → on ne redérange pas.
      if (empty($u['account_activation_pending']) && !empty($u['mot_de_passe'])) { return 'deja_present'; }
      // Compte encore en attente : en temps réel (auto) on NE renvoie PAS un 2e
      // mail (la personne a déjà reçu son lien, ex. inscrite à la borne).
      if (!$resendPending) { return 'deja_present'; }
      // 🔧 L'id du compte est un placeholder (« Compte… » ou vide, créé avant
      // qu'on ait les infos) → on le régénère AVANT d'envoyer, pour que le mail
      // affiche un vrai identifiant : PrénomN si on a le prénom, sinon dérivé de
      // l'e-mail. On complète aussi prénom/nom s'ils étaient vides.
      if (idEstPlaceholder($u['identifiant'] ?? '')) {
        $nouveau = identifiantLibre($db, baseIdFrom($prenom, $email), $nom);
        $np = $prenom !== '' ? $prenom : null;
        $nn = $nom !== '' ? $nom : null;
        try { $db->prepare('UPDATE utilisateurs SET identifiant = ?, prenom = COALESCE(?, prenom), nom = COALESCE(?, nom) WHERE id = ?')
                 ->execute([$nouveau, $np, $nn, (int) $u['id']]); } catch (Throwable $e) {}
      }
      return envoiFunActivation($db, (int) $u['id'], $heures) ? 'renvoye' : 'mail_ko';
    }
    // 2) Aucun compte : on le crée (comme à l'inscription) puis mail. L'id part
    // du prénom, ou de l'e-mail si pas de prénom → jamais « Compte ».
    $identifiant = identifiantLibre($db, baseIdFrom($prenom, $email), $nom);
    $ins = $db->prepare('INSERT INTO utilisateurs (identifiant, nom, prenom, email, mot_de_passe, role, account_activation_pending, site_id, statut_date)
                         VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
    $ins->execute([$identifiant, $nom, $prenom, $email,
      password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), roleInscription($prenom, $nom), $siteId, date('Y-m-d H:i:s')]);
    $uid = (int) $db->lastInsertId();
    if (envoiFunActivation($db, $uid, $heures)) { return 'cree'; }
    try { $db->prepare('DELETE FROM utilisateurs WHERE id = ?')->execute([$uid]); } catch (Throwable $e) {}
    return 'mail_ko';
  } catch (Throwable $e) {
    return 'erreur';
  }
}

// 🏬 Renvoie le site_id (widget_sites.ville) du magasin courant, ou null.
function siteIdCourant(PDO $db) {
  global $SITES, $SITE;
  try {
    $qs = $db->prepare('SELECT id FROM widget_sites WHERE ville = ? LIMIT 1');
    $qs->execute([$SITES[$SITE]['ville']]);
    $v = $qs->fetchColumn();
    return $v !== false ? (int) $v : null;
  } catch (Throwable $e) { return null; }
}

// 🎁 ─── RÉCOMPENSES : mails automatiques « viens voir les RH » ───────────────
// Un jardin est-il TERMINÉ ? (grille pleine + les 3 lotus)
function jardinEstComplet($cases) {
  global $LOTUS_REQUIS, $JARDIN_CASES;
  if (!is_array($cases) || count($cases) < $JARDIN_CASES) { return false; }
  $lotus = [];
  foreach ($cases as $c) { $pl = is_array($c) ? (string) ($c['plante'] ?? '') : ''; if (in_array($pl, $LOTUS_REQUIS, true)) { $lotus[$pl] = true; } }
  return count($lotus) >= count($LOTUS_REQUIS);
}
// A-t-on déjà prévenu cette personne (identifiant minuscule) ? / marquer prévenu.
function dejaPrevenu($cle) {
  global $rhFile;
  $d = readJson($rhFile);
  return is_array($d) && !empty($d['prevenu'][$cle]);
}

/**
 * 📨 Enregistre qu'un mail de récompense est parti, pour que la page RH le voie.
 *
 * ⚠️ Ce comptage était fait UNIQUEMENT dans l'action rh_mail. Résultat : le mail
 * AUTOMATIQUE (jardin terminé, podium du 31/08) partait bien, mais la page RH
 * affichait « aucun mail envoyé » pour cette personne — impossible de savoir si
 * elle avait été prévenue. On compte donc désormais dans mailRecompense(), le
 * seul endroit qui envoie : automatique ou manuel, tout est tracé au même titre.
 */
/**
 * 📜 RANGEMENT, UNE FOIS POUR TOUTES, DES ENVOIS D'AVANT LA SÉPARATION.
 *
 * Les compteurs n'étaient pas rangés par récompense. Ces envois-là sont
 * pourtant tous des mails « JARDIN » : le mail automatique du podium ne part
 * qu'après l'annonce des résultats (31/08 12h30), donc aucun n'a pu partir
 * avant. Les laisser sans motif les faisait apparaître sur la ligne PODIUM des
 * gagnants — « on prépare » s'affichait chez quelqu'un du podium alors que le
 * mail concernait son jardin, et il fallait se souvenir à chaque fois que ce
 * n'était pas ça.
 *
 * On les déplace donc dans le compteur « jardin ». Les valeurs d'origine sont
 * conservées dans « _avant_migration » : rien n'est détruit.
 */
function migreCompteursVersJardin() {
  global $rhFile;
  $d = readJson($rhFile);
  if (!is_array($d) || !empty($d['mails_ranges_jardin']) || empty($d['mails'])) { return; }
  withLock($rhFile, function (&$data, &$write) {
    if (!is_array($data) || !empty($data['mails_ranges_jardin'])) { return; }
    foreach (($data['mails'] ?? []) as $cle => $c) {
      if (!is_array($c)) { continue; }
      $att = (int) ($c['attente'] ?? 0);
      $pre = (int) ($c['prete'] ?? 0);
      if ($att === 0 && $pre === 0) { continue; }
      if (!isset($data['mails'][$cle]['jardin']) || !is_array($data['mails'][$cle]['jardin'])) {
        $data['mails'][$cle]['jardin'] = [];
      }
      $j = &$data['mails'][$cle]['jardin'];
      $j['attente'] = (int) ($j['attente'] ?? 0) + $att;
      $j['prete']   = (int) ($j['prete'] ?? 0) + $pre;
      if (empty($j['dernier']) && !empty($c['dernier'])) { $j['dernier'] = $c['dernier']; }
      if (empty($j['origine']) && !empty($c['origine'])) { $j['origine'] = $c['origine']; }
      unset($j);
      // On garde une trace de ce qu'il y avait, au cas où.
      $data['mails'][$cle]['_avant_migration'] = ['attente' => $att, 'prete' => $pre,
                                                  'dernier' => (string) ($c['dernier'] ?? '')];
      unset($data['mails'][$cle]['attente'], $data['mails'][$cle]['prete'],
            $data['mails'][$cle]['dernier'], $data['mails'][$cle]['origine']);
    }
    $data['mails_ranges_jardin'] = 1;
    $write = true;
  });
}

function noteEnvoiRecompense($cle, $modele, $origine = 'auto', $motif = 'podium') {
  global $rhFile;
  $motif = ($motif === 'jardin') ? 'jardin' : 'podium';
  withLock($rhFile, function (&$data, &$write) use ($cle, $modele, $origine, $motif) {
    if (!is_array($data)) { $data = []; }
    if (!isset($data['mails']) || !is_array($data['mails'])) { $data['mails'] = []; }
    if (!isset($data['mails'][$cle]) || !is_array($data['mails'][$cle])) { $data['mails'][$cle] = []; }
    // ⚠️ COMPTEURS SÉPARÉS PAR MOTIF (podium / jardin). Une même personne peut
    // être sur le podium ET avoir terminé son jardin : ce sont deux récompenses
    // et deux mails distincts. Avec un compteur unique, la page RH affichait
    // « mail envoyé » dans les DEUX sections dès qu'un seul était parti — on
    // croyait avoir prévenu quelqu'un pour son podium alors que le mail
    // concernait son jardin.
    if (!isset($data['mails'][$cle][$motif]) || !is_array($data['mails'][$cle][$motif])) {
      $data['mails'][$cle][$motif] = [];
    }
    $b = &$data['mails'][$cle][$motif];
    $b[$modele] = (int) ($b[$modele] ?? 0) + 1;
    $b['dernier'] = date('c');
    // Origine du TOUT PREMIER mail de ce motif : parti tout seul, ou envoyé à
    // la main ? La page RH l'affiche, pour qu'on sache si la personne est déjà
    // au courant sans que personne n'ait rien fait.
    if (!isset($b['origine'])) { $b['origine'] = $origine; }
    unset($b);
    $write = true;
  });
}
function marquePrevenu($cle) {
  global $rhFile;
  withLock($rhFile, function (&$data, &$write) use ($cle) {
    if (!is_array($data)) { $data = []; }
    if (!isset($data['prevenu']) || !is_array($data['prevenu'])) { $data['prevenu'] = []; }
    $data['prevenu'][$cle] = 1; $write = true;
  });
}
// ✉️ Envoie le mail « viens voir les RH » à une personne (par identifiant
// minuscule). $info = ['type'=>'podium'|'jardin','rang'=>?]. true si parti.
/* ============================================================
   ✉️ TEXTES DES RÉCOMPENSES — modifiables depuis /quiz/admin
   ============================================================
   Tous les messages qu'une personne voit ou reçoit quand elle gagne quelque
   chose sont réunis ICI, avec leur texte par défaut. L'onglet « Messages » de
   l'administration les enregistre dans messages.json (dossier de données) ;
   ce fichier ne contient QUE ce qui a été modifié, le reste retombe sur ces
   valeurs. Vider un champ dans l'admin revient donc à revenir au texte d'origine.

   Les {accolades} sont des trous remplis par le jeu :
     {graines} nombre de graines récoltées · {rang} 1er, 2e, 3e
     {place} « 1re place », « 2e place » · {prenom} prénom de la personne
   Les mails n'existent qu'en français : ils sont écrits à la main par les RH.
   ============================================================ */
$MESSAGES_DEFAUT = [
  // ── 🎉 Fenêtre affichée à la fin du quiz ──────────────────────────────
  'quiz_fini_titre' => ['groupe' => '🎉 Fenêtre — fin du quiz', 'libelle' => 'Titre de la fenêtre',
    'fr' => 'Quiz terminé !', 'nl' => 'Quiz voltooid!'],
  'quiz_fini_corps' => ['groupe' => '🎉 Fenêtre — fin du quiz', 'libelle' => 'Message',
    'lignes' => true, 'trous' => '{graines}',
    'fr' => "Tu as récolté <b>{graines} 🌰</b> graines.\n"
          . "<b>Ton jardin est maintenant ouvert</b> 🌼 : plantes-y tes graines. Elles vont diminuer — <b>c'est normal, ça ne concerne que le jardin</b>, <b>ton score au classement ne bougera pas</b>.\n"
          . "🎁 Et surtout : <b>va au bout de ton jardin et tu gagnes une récompense toi aussi</b>, même sans être sur le podium !",
    'nl' => "Je hebt <b>{graines} 🌰</b> zaadjes geoogst.\n"
          . "<b>Je tuin is nu open</b> 🌼: plant er je zaadjes. Ze zullen afnemen — <b>dat is normaal, het geldt enkel voor de tuin</b>, <b>je score in de ranking verandert niet</b>.\n"
          . "🎁 En vooral: <b>werk je tuin af en jij wint ook een beloning</b>, ook zonder op het podium te staan!"],

  // ── 🏆 Fenêtre affichée quand le jardin est terminé ───────────────────
  'jardin_gagne_titre' => ['groupe' => '🏆 Fenêtre — jardin terminé', 'libelle' => 'Titre de la fenêtre',
    'fr' => 'Bravo, tu as gagné !', 'nl' => 'Bravo, je hebt gewonnen!'],
  'jardin_gagne_corps' => ['groupe' => '🏆 Fenêtre — jardin terminé', 'libelle' => 'Message',
    'lignes' => true,
    'fr' => "Tu as <b>complété tout ton jardin</b> et planté les <b>3 lotus</b> (or, argent, bronze) 🌸\n"
          . "Tu fais officiellement partie des <b>gagnants du jardin</b> 🎁 <b>Ta récompense est en cours de préparation.</b> Nous t'enverrons un mail une fois que tu pourras venir la chercher.",
    'nl' => "Je hebt <b>je hele tuin voltooid</b> en de <b>3 lotussen</b> (goud, zilver, brons) geplant 🌸\n"
          . "Je hoort officieel bij de <b>winnaars van de tuin</b> 🎁 <b>Je beloning wordt klaargemaakt.</b> We sturen je een mail zodra je ze mag komen ophalen."],

  // ── 🌼 Encart « objectif récompense », dans le jardin ─────────────────
  'objectif_ok' => ['groupe' => '🌼 Encart du jardin', 'libelle' => 'Quand le jardin est terminé',
    'fr' => "Bravo&nbsp;! Ton jardin est complet et tes <b>3 lotus</b> sont plantés — tu es <b>gagnant du jardin</b> 🌟 Ta récompense est en cours de préparation, tu recevras un mail dès qu'elle sera prête.",
    'nl' => 'Bravo&nbsp;! Je tuin is volledig en je <b>3 lotussen</b> zijn geplant — je bent <b>winnaar van de tuin</b> 🌟 Je beloning wordt klaargemaakt, je krijgt een mail zodra ze klaar is.'],
  'objectif_aide' => ['groupe' => '🌼 Encart du jardin', 'libelle' => 'Tant qu\'il n\'est pas terminé',
    'fr' => 'Remplis <b>toutes</b> tes cases et plante les <b>3 lotus (or, argent, bronze)</b> pour gagner ta récompense.',
    'nl' => 'Vul <b>al</b> je vakjes en plant de <b>3 lotussen (goud, zilver, brons)</b> om je beloning te winnen.'],

  // ── 🥇 Bandeau affiché aux 3 premiers, après l'annonce ────────────────
  'podium_bandeau' => ['groupe' => '🥇 Bandeau podium', 'libelle' => 'Message affiché aux 3 premiers', 'trous' => '{rang}',
    'fr' => 'Bravo, tu finis <b>{rang}</b> du classement ! Ta récompense est en cours de préparation — tu recevras un mail dès que tu pourras venir la chercher.',
    'nl' => 'Proficiat, je eindigt <b>{rang}</b> in de ranking! Je beloning wordt klaargemaakt — je krijgt een mail zodra je ze mag komen ophalen.'],

  // ── ✉️ Mail « on prépare », version podium ────────────────────────────
  // 🇧🇪 Les mails partent en FRANÇAIS PUIS EN NÉERLANDAIS, dans le même envoi :
  // on ne connaît pas la langue de chaque personne (la base ne la stocke pas),
  // et un mail de félicitations qu'on ne comprend pas ne félicite personne.
  'mail_podium_sujet' => ['groupe' => '✉️ Mail — podium', 'libelle' => 'Objet du mail',
    'fr' => '🏆 Bravo — on prépare ta récompense !',
    'nl' => '🏆 Proficiat — we maken je beloning klaar!'],
  'mail_podium_corps' => ['groupe' => '✉️ Mail — podium', 'libelle' => 'Message', 'lignes' => true, 'trous' => '{place}',
    'fr' => "L'équipe Famiformation te félicite pour ta <b>{place}</b> au grand quiz&nbsp;! 🏆\n"
          . "On <b>prépare ta récompense</b>. Nous t'enverrons un mail une fois que tu pourras venir la chercher.",
    'nl' => "Het Famiformation-team feliciteert je met je <b>{place}</b> in de grote quiz&nbsp;! 🏆\n"
          . "We <b>maken je beloning klaar</b>. Je krijgt een mail zodra je ze mag komen ophalen."],

  // ── ✉️ Mail « on prépare », version jardin ────────────────────────────
  'mail_jardin_sujet' => ['groupe' => '✉️ Mail — jardin terminé', 'libelle' => 'Objet du mail',
    'fr' => '🌼 Bravo — on prépare ta récompense !',
    'nl' => '🌼 Proficiat — we maken je beloning klaar!'],
  'mail_jardin_corps' => ['groupe' => '✉️ Mail — jardin terminé', 'libelle' => 'Message', 'lignes' => true,
    'fr' => "L'équipe Famiformation te félicite pour avoir <b>terminé ton jardin</b>&nbsp;! 🌼\n"
          . "On <b>prépare ta récompense</b>. Nous t'enverrons un mail une fois que tu pourras venir la chercher.",
    'nl' => "Het Famiformation-team feliciteert je omdat je <b>je tuin hebt afgewerkt</b>&nbsp;! 🌼\n"
          . "We <b>maken je beloning klaar</b>. Je krijgt een mail zodra je ze mag komen ophalen."],

  // ── ✉️ Mail « c'est prêt », envoyé par les RH ─────────────────────────
  // Deux versions, comme pour « on prépare » : quelqu'un peut être sur le
  // podium ET avoir terminé son jardin. Il recevra alors deux mails, et doit
  // pouvoir dire lequel concerne quoi.
  'mail_prete_podium_sujet' => ['groupe' => "✉️ Mail — podium : c'est prêt", 'libelle' => 'Objet du mail',
    'fr' => '🏆 Ta récompense du podium est prête !',
    'nl' => '🏆 Je podiumbeloning ligt klaar!'],
  'mail_prete_podium_corps' => ['groupe' => "✉️ Mail — podium : c'est prêt", 'libelle' => 'Message', 'lignes' => true,
    'fr' => "Bonne nouvelle : <b>ta récompense du podium est prête</b>&nbsp;! 🏆\n"
          . "Pour la récupérer, présente-toi <b>auprès des RH</b> du magasin.",
    'nl' => "Goed nieuws: <b>je podiumbeloning ligt klaar</b>&nbsp;! 🏆\n"
          . "Meld je aan <b>bij de HR-dienst</b> van de winkel om ze op te halen."],
  'mail_prete_jardin_sujet' => ['groupe' => "✉️ Mail — jardin : c'est prêt", 'libelle' => 'Objet du mail',
    'fr' => '🌼 Ta récompense du jardin est prête !',
    'nl' => '🌼 Je tuinbeloning ligt klaar!'],
  'mail_prete_jardin_corps' => ['groupe' => "✉️ Mail — jardin : c'est prêt", 'libelle' => 'Message', 'lignes' => true,
    'fr' => "Bonne nouvelle : <b>ta récompense pour ton jardin terminé est prête</b>&nbsp;! 🌼\n"
          . "Pour la récupérer, présente-toi <b>auprès des RH</b> du magasin.",
    'nl' => "Goed nieuws: <b>je beloning voor je afgewerkte tuin ligt klaar</b>&nbsp;! 🌼\n"
          . "Meld je aan <b>bij de HR-dienst</b> van de winkel om ze op te halen."],

  // Les MODALITÉS (le « Comment ça marche ») ne sont volontairement PAS ici :
  // ce sont les règles du jeu, pas un message de félicitations. Les mettre à
  // côté des mails de récompense allongeait la page sans rendre service. Elles
  // restent dans quiz/index.html, à côté du reste de l'explication.
];

/**
 * Les textes en vigueur POUR LE MAGASIN COURANT : les valeurs par défaut,
 * écrasées par messages-<magasin>.json. Chaque magasin a donc les siens.
 */
function messagesActuels() {
  global $MESSAGES_DEFAUT, $messagesFile;
  static $cache = null;
  if ($cache !== null) { return $cache; }
  $perso = readJson($messagesFile);
  $out = [];
  foreach ($MESSAGES_DEFAUT as $cle => $def) {
    foreach (['fr', 'nl'] as $lang) {
      if ($def[$lang] === null) { continue; }
      $v = is_array($perso) ? trim((string) ($perso[$cle][$lang] ?? '')) : '';
      $out[$cle][$lang] = $v !== '' ? $v : $def[$lang];
    }
  }
  return $cache = $out;
}
/** Un texte, avec ses trous remplis. */
function msgTexte($cle, $lang = 'fr', $trous = []) {
  $m = messagesActuels();
  $t = $m[$cle][$lang] ?? ($m[$cle]['fr'] ?? '');
  foreach ($trous as $k => $v) { $t = str_replace('{' . $k . '}', (string) $v, $t); }
  return $t;
}

function mailRecompense(PDO $db, $cle, $info, $modele = 'attente', $origine = 'auto') {
  try {
    $q = $db->prepare('SELECT email, prenom, nom FROM utilisateurs WHERE LOWER(identifiant) = ? LIMIT 1');
    $q->execute([$cle]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return false; }
  $email = $u ? trim((string) ($u['email'] ?? '')) : '';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return false; }
  // 👋 Le prénom, remis en forme : la base contient aussi bien « ENYLSON » que
  // « enylson » selon la façon dont le compte a été créé.
  $prenom = prenomAffichable($u['prenom'] ?? '');
  $bonjour = $prenom !== '' ? $prenom : 'à toi';
  $modele = ($modele === 'prete') ? 'prete' : 'attente';

  // Le MOTIF de la récompense : podium avec son rang, ou jardin terminé.
  $estPodium = (($info['type'] ?? '') === 'podium');
  $rang = (int) ($info['rang'] ?? 0);
  $place = ($rang === 1) ? '1re place' : $rang . 'e place';

  // 📝 Les textes viennent de l'onglet « Messages » de l'administration.
  // Un mail = un OBJET + un MESSAGE, le message pouvant tenir sur plusieurs
  // lignes (une ligne = un paragraphe). Le « Bonjour », le lien de contact et
  // la signature restent automatiques : ils ne changent jamais.
  if ($modele === 'attente') {
    // 1️⃣ ON PRÉPARE. Envoyé dès que la personne devient gagnante : on félicite,
    // on annonce qu'un second mail suivra. Sans ça, quelqu'un qui gagne le
    // 20/08 n'entendait plus parler de rien jusqu'au 01/09.
    $cleSujet = $estPodium ? 'mail_podium_sujet' : 'mail_jardin_sujet';
    $cleCorps = $estPodium ? 'mail_podium_corps' : 'mail_jardin_corps';
    $sujet   = msgTexte($cleSujet, 'fr');
    $sujetNl = msgTexte($cleSujet, 'nl');
    $corps   = msgTexte($cleCorps, 'fr', ['place' => $place]);
    $corpsNl = msgTexte($cleCorps, 'nl', ['place' => $place]);
  } else {
    // 2️⃣ C'EST PRÊT. Envoyé par les RH le jour où la récompense est disponible.
    // Plus de rappel du classement ici : à ce stade la seule information utile,
    // c'est qu'elle est prête et où la chercher.
    $cleSujet = $estPodium ? 'mail_prete_podium_sujet' : 'mail_prete_jardin_sujet';
    $cleCorps = $estPodium ? 'mail_prete_podium_corps' : 'mail_prete_jardin_corps';
    $sujet   = msgTexte($cleSujet, 'fr');
    $sujetNl = msgTexte($cleSujet, 'nl');
    $corps   = msgTexte($cleCorps, 'fr');
    $corpsNl = msgTexte($cleCorps, 'nl');

    // 🎟️ LE BON CADEAU NE CONCERNE QUE LE JARDIN TERMINÉ.
    //
    // Le stock de codes est réservé aux jardins ; le podium a ses propres lots,
    // remis en main propre. Un mail de podium part donc SANS code, exactement
    // comme avant — et n'est pas bloqué par la règle du code unique.
    if (!$estPodium) {
      if (estCompteAdminTest($cle)) {
        // 🧪 Compte de test : faux bon, stock intact. Autant de fois qu'on veut.
        $codeRecompense = recompenseCodeTest();
      } else {
        // Un code, une personne : si elle a déjà le sien, on n'envoie RIEN. Le
        // mail contiendrait un second bon alors que le premier reste valable.
        $dejaCode = recompenseCodeExistant($db, $cle);
        if ($dejaCode) {
          return false;
        }

        $code = recompenseAttribue($db, $cle, $bonjour, $email, 'jardin');
        if (!$code) {
          // Stock épuisé : on n'envoie pas un mail « c'est prêt » sans le bon.
          // Mieux vaut que les RH voient l'envoi échouer et rechargent des codes.
          return false;
        }
        $codeRecompense = $code;
      }
    }
  }

  // Une ligne du message = un paragraphe. Les lignes vides sont ignorées, pour
  // qu'un retour à la ligne en trop ne fabrique pas un blanc dans le mail.
  $enParagraphes = function ($texte) {
    $out = '';
    foreach (preg_split('/\R/u', (string) $texte) as $ligne) {
      $ligne = trim($ligne);
      if ($ligne === '') { continue; }
      $out .= '<p style="font-size:16px;line-height:1.6;">' . $ligne . '</p>';
    }
    return $out;
  };
  $paragraphes = $enParagraphes($corps);

  // 🇧🇪 La version néerlandaise, dans le MÊME mail. On ne connaît pas la langue
  // de chaque personne (la base ne la stocke pas) : envoyer deux mails séparés
  // reviendrait à en envoyer un de trop, ou le mauvais. Si la traduction est
  // vide — un magasin a pu effacer le champ NL depuis l'admin — on n'ajoute
  // rien plutôt qu'un séparateur suivi du blanc.
  $blocNl = '';
  $parasNl = $enParagraphes($corpsNl);
  if (trim($parasNl) !== '' && trim($corpsNl) !== trim($corps)) {
    $blocNl =
      '<div style="border-top:2px dashed #d6dfd8;margin:26px 0 6px;"></div>'
      . '<p style="font-size:13px;font-weight:bold;color:#617268;letter-spacing:1px;margin:0 0 10px;">🇳🇱 NEDERLANDS</p>'
      . '<p style="font-size:16px;">Hallo ' . htmlspecialchars($bonjour, ENT_QUOTES, 'UTF-8') . ',</p>'
      . $parasNl;
  }

  // 🎟️ LE BON CADEAU. Le code est écrit EN TOUTES LETTRES en plus du
  // code-barres : la plupart des clients mail bloquent les images distantes par
  // défaut, et un bon illisible ne sert à rien. La caisse peut donc le saisir à
  // la main si l'image ne s'affiche pas.
  //
  // 🍬 CE QUE LE BON DONNE est écrit noir sur blanc — une boîte Fun chez
  // Lollyland, 5 €. Sans ça, la personne se présente en caisse sans savoir à
  // quoi elle a droit, et c'est le comptoir qui doit l'expliquer à chaque fois.
  $blocCode = '';
  if (!empty($codeRecompense)) {
    $bar = (string) $codeRecompense['barcode'];
    $blocCode =
      '<div style="margin:24px 0;padding:20px;border:2px dashed #d6a21a;border-radius:14px;background:#fffbf0;text-align:center;">'
      . '<div style="font-size:13px;font-weight:bold;color:#8a6d1a;letter-spacing:.06em;text-transform:uppercase;">Ton bon cadeau&nbsp;· Jouw cadeaubon</div>'
      . '<div style="margin:8px 0 4px;font-size:19px;font-weight:bold;color:#244230;">🍬 Une boîte Fun chez Lollyland</div>'
      . '<div style="font-size:14px;color:#8a6d1a;">d\'une valeur de 5&nbsp;€, offerte par la maison</div>'
      . '<div style="margin-top:6px;font-size:17px;font-weight:bold;color:#244230;">🍬 Een Fun-box bij Lollyland</div>'
      . '<div style="font-size:14px;color:#8a6d1a;">ter waarde van 5&nbsp;€, aangeboden door het huis</div>'
      . '<div style="margin:14px 0 6px;"><img src="' . htmlspecialchars(recompenseUrlCodeBarre($bar), ENT_QUOTES, 'UTF-8')
      . '" alt="' . htmlspecialchars($bar, ENT_QUOTES, 'UTF-8') . '" width="300" style="max-width:100%;height:auto;"></div>'
      . '<div style="font-family:monospace;font-size:19px;font-weight:bold;color:#244230;letter-spacing:.04em;">'
      . htmlspecialchars($bar, ENT_QUOTES, 'UTF-8') . '</div>'
      . '<div style="margin-top:10px;font-size:13px;color:#617268;">Présente ce code en caisse. Il est personnel et utilisable une seule fois.<br>'
      . 'Toon deze code aan de kassa. Hij is persoonlijk en één keer bruikbaar.</div>'
      . '</div>';
  }

  // 🎟️ Le bon cadeau est placé APRÈS les deux langues : il est unique, et le
  // répéter donnerait l'impression d'avoir reçu deux bons.
  $body = '<div style="font-family:Arial,sans-serif;color:#244230;max-width:560px;margin:0 auto;padding:24px;">'
    . '<p style="font-size:16px;">Bonjour ' . htmlspecialchars($bonjour, ENT_QUOTES, 'UTF-8') . ',</p>'
    . $paragraphes
    . $blocNl
    . $blocCode
    . '<p style="font-size:16px;line-height:1.6;">Une question&nbsp;? Écris à <a href="mailto:admin@famiformation.com">admin@famiformation.com</a>.<br>'
    . 'Een vraag&nbsp;? Mail naar <a href="mailto:admin@famiformation.com">admin@famiformation.com</a>.</p>'
    . '<p style="font-size:15px;color:#617268;">Merci d\'avoir joué, et à bientôt&nbsp;! 🌱 · Bedankt om mee te spelen, tot binnenkort&nbsp;! 🌱<br>L\'équipe Famiflora · Famiformation</p></div>';

  // L'OBJET aussi porte les deux langues, séparées par «&nbsp;·&nbsp;» : c'est
  // la seule ligne visible avant d'ouvrir, elle doit parler à tout le monde.
  if (trim((string) $sujetNl) !== '' && trim((string) $sujetNl) !== trim((string) $sujet)) {
    $sujet = $sujet . ' · ' . $sujetNl;
  }
  $ok = function_exists('sendMail') ? sendMail($email, $sujet, $body, true) : false;
  // Tracé ICI, donc pour TOUS les envois — automatiques comme manuels, et
  // séparément selon qu'il s'agit du podium ou du jardin.
  if ($ok) { noteEnvoiRecompense($cle, $modele, $origine, $estPodium ? 'podium' : 'jardin'); }
  // On prévient l'admin (RH) uniquement au PREMIER mail (« on prépare ») : c'est
  // là qu'il y a quelque chose à préparer. Le second part de sa main, inutile de
  // le lui annoncer.
  if ($ok && $modele === 'attente' && $origine === 'auto') { mailAdminRecompense($cle, $u, $info); }
  return $ok;
}

// 📣 Notifie l'ADMIN (RH) qu'une personne vient de devenir éligible à une
// récompense (jardin terminé ou podium), pour qu'il la prépare. Destinataire
// configurable via la variable d'env RH_NOTIF_MAIL (sinon adresse par défaut).
function mailAdminRecompense($cle, $u, $info) {
  if (!function_exists('sendMail')) { return false; }
  $dest = getenv('RH_NOTIF_MAIL');
  if ($dest === false || $dest === '') { $dest = 'enylson.laine@famiflora.be'; }
  $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
  $qui = trim(trim((string) ($u['prenom'] ?? '')) . ' ' . trim((string) ($u['nom'] ?? '')));
  if ($qui === '') { $qui = (string) $cle; }
  if (($info['type'] ?? '') === 'podium') {
    $rang = (int) ($info['rang'] ?? 0);
    $motif = 'Podium — ' . ($rang === 1 ? '1re place 🥇' : ($rang === 2 ? '2e place 🥈' : ($rang === 3 ? '3e place 🥉' : $rang . 'e place')));
    $sujet = '🎁 Récompense à préparer (podium) — ' . $qui;
  } else {
    $motif = 'Jardin terminé 🌼';
    $sujet = '🎁 Récompense à préparer (jardin) — ' . $qui;
  }
  $mailPers = trim((string) ($u['email'] ?? ''));
  $body = '<div style="font-family:Arial,sans-serif;color:#244230;max-width:560px;margin:0 auto;padding:24px;">'
    . '<p style="font-size:16px;"><b>🎁 Nouvelle récompense à préparer</b></p>'
    . '<p style="font-size:15px;line-height:1.7;">Une personne vient de devenir éligible à une récompense du quiz Famiformation.</p>'
    . '<table style="font-size:15px;line-height:1.9;border-collapse:collapse;">'
    . '<tr><td style="padding-right:12px;"><b>Personne</b></td><td>' . $e($qui) . '</td></tr>'
    . '<tr><td style="padding-right:12px;"><b>Identifiant</b></td><td>' . $e($cle) . '</td></tr>'
    . ($mailPers !== '' ? '<tr><td style="padding-right:12px;"><b>E-mail</b></td><td>' . $e($mailPers) . '</td></tr>' : '')
    . '<tr><td style="padding-right:12px;"><b>Motif</b></td><td>' . $motif . '</td></tr>'
    . '</table>'
    . '<p style="font-size:15px;line-height:1.7;">👉 Liste complète des récompenses à remettre dans l\'espace <b>RH du quiz</b> (/quiz/rh).</p>'
    . '<p style="font-size:13px;color:#617268;">Notification automatique · Famiformation</p></div>';
  return sendMail($dest, $sujet, $body, true);
}
// 🏆 Envoi AUTOMATIQUE aux 3 vainqueurs, une fois l'heure des résultats passée
// (31/08 12h30, heure belge). Ne part qu'UNE fois (drapeau dans le fichier RH).
// Appelé paresseusement depuis une action fréquente (board) : le test d'heure est
// gratuit tant qu'on est avant, et une fois envoyé le drapeau court-circuite tout.
function verifieVainqueursAuto() {
  global $scoresFile, $rhFile, $COMPTES_TEST;
  static $faitCeTour = false;
  if ($faitCeTour) { return; }
  try { $heure = (new DateTime('2026-08-31 12:30:00', new DateTimeZone('Europe/Brussels')))->getTimestamp(); }
  catch (Throwable $e) { return; }
  if (time() < $heure) { return; }
  $d = readJson($rhFile);
  if (is_array($d) && !empty($d['vainqueurs_envoye'])) { return; }
  $faitCeTour = true;
  $db = famiDb();
  if (!$db) { return; }
  $joueurs = [];
  foreach ((is_array(readJson($scoresFile)) ? readJson($scoresFile) : []) as $p) {
    if (!is_array($p) || ($p['quiz_fait'] ?? true) === false) { continue; }
    if (estCompteTest($p)) { continue; }
    $joueurs[] = $p;
  }
  usort($joueurs, function ($a, $b) {
    $x = floatval($b['score'] ?? 0) - floatval($a['score'] ?? 0);
    if ($x > 0) { return 1; } if ($x < 0) { return -1; }
    return intval($a['time'] ?? 0) - intval($b['time'] ?? 0);
  });
  $rang = 1;
  foreach (array_slice($joueurs, 0, 3) as $p) {
    $cle = mb_strtolower((string) $p['name']);
    if (!dejaPrevenu($cle) && mailRecompense($db, $cle, ['type' => 'podium', 'rang' => $rang])) { marquePrevenu($cle); }
    $rang++;
  }
  withLock($rhFile, function (&$data, &$write) { if (!is_array($data)) { $data = []; } $data['vainqueurs_envoye'] = 1; $write = true; });
}

// 🛡️ GARDE-FOU sur les actions qui ENVOIENT UN MAIL ou CRÉENT UN COMPTE.
// Sans ça, n'importe qui peut marteler l'inscription : boîte mail d'un tiers
// inondée, table des utilisateurs remplie de faux comptes.
//
// ⚠️ ATTENTION au réglage : à la borne du magasin, TOUS les visiteurs sortent
// par la MÊME adresse IP. Une limite serrée par IP bloquerait donc de vrais
// clients le jour de l'événement. On protège donc surtout PAR ADRESSE MAIL
// (3 envois/heure : personne ne peut inonder la boîte de quelqu'un), et on garde
// un plafond par IP très large, uniquement contre un script automatisé.
function tropDEnvois($cle, $max, $fenetre = 3600) {
  global $dataDir;
  $trop = false;
  withLock($dataDir . '/envois.json', function (&$journal, &$write) use ($cle, $max, $fenetre, &$trop) {
    $maintenant = time();
    if (!is_array($journal)) { $journal = []; }
    // On nettoie au passage tout ce qui est sorti de la fenêtre (le fichier ne
    // grossit donc jamais).
    foreach ($journal as $k => $dates) {
      $journal[$k] = array_values(array_filter((array) $dates, fn($t) => $maintenant - (int) $t < $fenetre));
      if (!$journal[$k]) { unset($journal[$k]); }
    }
    $miens = $journal[$cle] ?? [];
    if (count($miens) >= $max) { $trop = true; }
    else { $miens[] = $maintenant; $journal[$cle] = $miens; }
    $write = true;
    return null;
  });
  return $trop;
}

// Les deux garde-fous d'un envoi de mail : l'adresse visée d'abord (le vrai
// risque), l'IP ensuite (très large, pour ne pas gêner la borne du magasin).
function envoiRefuse($email) {
  $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'inconnu');
  return tropDEnvois('mail:' . mb_strtolower($email), 3) || tropDEnvois('ip:' . $ip, 120);
}

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?: [];

// 🏬 ON CLOISONNE PAR SITE. Chaque magasin a ses propres fichiers : un joueur, un
// score, un code, une question de Mouscron ne touchent JAMAIS ceux de La Panne.
// Le suffixe « -<site> » sur chaque fichier suffit à garantir qu'ils ne se
// croisent pas. Les jetons de session et le journal anti-abus restent communs.
$SITE = siteDe($input, $SITES, $SITE_DEFAUT);
$scoresFile    = $dataDir . "/scores-$SITE.json";
$codesFile     = $dataDir . "/codes-$SITE.json";
$indicesFile   = $dataDir . "/codes-indices-$SITE.json";   // 🔎 indice (cache) par code, saisi en admin
$questionsFile = $dataDir . "/questions-$SITE.json";
$jardinFile    = $dataDir . "/jardin-$SITE.json";
$configFile    = $dataDir . "/config-$SITE.json";
$rhFile        = $dataDir . "/rh-$SITE.json";   // récompenses remises (coché par les RH)
// 💬 Textes des récompenses : PAR MAGASIN, comme tout le reste. Mouscron et
// La Panne n'ont ni les mêmes lots ni la même organisation — leurs messages
// doivent donc pouvoir différer.
$messagesFile  = $dataDir . "/messages-$SITE.json";
$BONUS_CODES   = array_merge($BONUS_CODES_PAR_SITE[$SITE], [$CODE_TEST_OK, $CODE_TEST_USED]);

switch ($action) {

  // 📊 Récupérer le classement (lecture seule)
  case 'board': {
    // 🏆 Déclencheur paresseux : passé le 31/08 12h30, envoie (une fois) les mails
    // aux 3 vainqueurs. Le board est appelé très souvent (télé, joueurs) → fiable.
    verifieVainqueursAuto();
    $board = readJson($scoresFile);
    // Au classement, on ne montre QUE ceux qui ont réellement joué : un compte
    // créé (réservé) mais pas encore joué (quiz_fait=false) ne pollue pas la liste.
    $board = array_values(array_filter($board, fn($p) => ($p['quiz_fait'] ?? true) && !estCompteTest($p)));
    sortBoard($board);
    echo json_encode($board, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🔢 Combien de codes bonus restent à trouver en magasin (hors codes de test).
  case 'codes_restants': {
    $claimed = readJson($codesFile);
    $reels = array_values(array_filter($BONUS_CODES, fn($c) => $c !== $CODE_TEST_OK && $c !== $CODE_TEST_USED));
    $total = count($reels);
    $pris = 0;

    // 🎯 RÉPARTITION PAR LIEU, calculée sur les codes ENCORE À TROUVER.
    //
    // Le lieu de chaque code est saisi dans l'admin (action code_indice) et rangé
    // dans codes-indices-<site>.json. On regroupe ici les codes NON réclamés par
    // lieu : dès que quelqu'un récupère un code, le compteur de son emplacement
    // baisse tout seul, et l'emplacement disparaît de la liste quand son dernier
    // code est parti. Plus rien à tenir à jour à la main.
    //
    // Un code sans lieu renseigné n'apparaît nulle part : mieux vaut ne rien
    // annoncer qu'envoyer les gens fouiller une zone déjà vidée.
    $indices = readJson($indicesFile);
    if (!is_array($indices)) { $indices = []; }
    $parLieu = [];
    foreach ($reels as $c) {
      if (isset($claimed[$c])) { $pris++; continue; }
      $lieu = trim((string) ($indices[$c] ?? ''));
      if ($lieu === '') { continue; }
      $parLieu[$lieu] = ($parLieu[$lieu] ?? 0) + 1;
    }
    // Les emplacements les mieux fournis d'abord ; à égalité, ordre alphabétique.
    $zones = [];
    foreach ($parLieu as $nom => $nb) { $zones[] = ['nom' => $nom, 'nb' => $nb]; }
    usort($zones, function ($a, $b) {
      if ($a['nb'] !== $b['nb']) { return $b['nb'] - $a['nb']; }
      return strcasecmp($a['nom'], $b['nom']);
    });

    echo json_encode([
      'total'    => $total,
      'restants' => max(0, $total - $pris),
      'pris'     => $pris,
      'zones'    => $zones,
    ], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🏁 Enregistrer un score.
  // La vérification du doublon et l'ajout se font DANS le même verrou : c'est ce
  // qui garantit qu'un seul « Marie » entre dans la liste, même si deux Marie
  // valident exactement au même instant.
  case 'submit': {
    $name = trim($input['name'] ?? '');
    // Jusqu'à 60 : un identifiant Famiformation (prenom.nom) peut dépasser 24.
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
      http_response_code(400);
      echo json_encode(['error' => 'Prénom invalide']);
      break;
    }
    // « code » = le code jardinier à 4 chiffres (secret rigolo qui sert à
    // récupérer son compte sur un autre téléphone). « nom » = Nom Prénom, saisi
    // facultativement, utile pour remettre les prix aux vrais gagnants.
    $codeJard = preg_replace('/\D/', '', (string)($input['code'] ?? ''));
    $entree = [
      'name'      => $name,                                   // clé du compte (= identifiant Famiformation)
      'pseudo'    => trim(mb_substr((string)($input['pseudo'] ?? ''), 0, 24)),  // nom AFFICHÉ au classement (choisi par le joueur)
      'code'      => substr($codeJard, 0, 4),                 // code secret à 4 chiffres
      'nom'       => trim(mb_substr((string)($input['nom'] ?? ''), 0, 60)),
      'score'     => max(0, round(floatval($input['score'] ?? 0), 1)),   // récolte (nombre à virgule)
      'bonus'     => 0,                                       // graines gagnées au mini-jeu
      'depensees' => 0,                                       // graines déjà plantées au jardin
      'correct'   => max(0, intval($input['correct'] ?? 0)),
      'codes'     => 0,                                       // nombre de codes bonus récupérés
      'codes_pris' => [],                                     // quels codes bonus ont été pris
      'time'      => max(0, intval($input['time'] ?? 0)),
      'date'      => date('c'),
    ];

    $res = withLock($scoresFile, function (&$board, &$write) use ($name, $entree, $input) {
      for ($i = 0; $i < count($board); $i++) {
        if (mb_strtolower($board[$i]['name'] ?? '') === mb_strtolower($name)) {
          // Compte existant. Il faut prouver que c'est bien SON compte : jeton
          // Famiformation valide, ou ancien code jardinier. Sinon, nom pris par
          // un autre (on refuse d'écraser sa récolte).
          if (!joueurAutorise($input, $name, $board[$i]['code'] ?? '')) {
            return ['conflit' => true];
          }
          // Quiz déjà fait : on ne réécrase pas la récolte (on renvoie l'état actuel).
          if (!empty($board[$i]['quiz_fait']) && !estCompteTest($board[$i])) {
            sortBoard($board);
            return ['deja' => true, 'board' => $board];
          }
          // Compte créé AVANT de jouer : on inscrit maintenant sa récolte du quiz.
          $board[$i]['score']    = $entree['score'];
          $board[$i]['correct']  = $entree['correct'];
          $board[$i]['time']     = $entree['time'];
          $board[$i]['quiz_fait'] = true;
          if ($entree['nom'] !== '') $board[$i]['nom'] = $entree['nom'];
          if ($entree['pseudo'] !== '') $board[$i]['pseudo'] = $entree['pseudo'];
          sortBoard($board);
          $write = true;
          return ['board' => $board];
        }
      }
      // Aucun compte à ce nom : création directe (joué sans passer par « créer un compte »).
      $entree['quiz_fait'] = true;
      $board[] = $entree;
      sortBoard($board);
      $write = true;
      return ['board' => $board];
    });

    if (!empty($res['conflit'])) {
      http_response_code(409);
      echo json_encode(['error' => 'nom_pris']);
      break;
    }

    // 📊 Trace de la participation, avec l'écran d'où elle vient.
    // Volontairement APRÈS le test de conflit et hors du cas « deja » : on ne
    // compte que les parties réellement enregistrées, sinon un joueur qui
    // rouvre sa page gonflerait les chiffres à chaque fois.
    if (empty($res['deja'])) {
      borneEvenement(famiDb(), $SITE, ecranDe($input), 'participation', $name, $entree['score']);
    }

    echo json_encode($res['board'], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 👤 Vérifier si un prénom a déjà joué (avant de démarrer le quiz).
  // Simple confort : la vraie garantie est dans 'submit', sous verrou.
  case 'check': {
    $name = mb_strtolower(trim($input['name'] ?? ''));
    $board = readJson($scoresFile);
    foreach ($board as $p) {
      if (mb_strtolower($p['name'] ?? '') === $name) {
        echo json_encode(['exists' => true]);
        exit;
      }
    }
    echo json_encode(['exists' => false]);
    break;
  }

  // ✨ CRÉER (réserver) un compte AVANT de jouer : pseudo + code à 4 chiffres.
  // Le compte entre dans les données avec score 0 et quiz_fait=false (donc pas au
  // classement tant qu'il n'a pas joué). Si le nom existe déjà : c'est TOI si le
  // code correspond (reconnexion), sinon le nom est pris.
  case 'register': {
    $name = trim($input['name'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 24) {
      http_response_code(400); echo json_encode(['error' => 'Pseudo invalide']); break;
    }
    $code4 = substr(preg_replace('/\D/', '', (string)($input['code'] ?? '')), 0, 4);
    if (strlen($code4) !== 4) {
      http_response_code(400); echo json_encode(['error' => 'code_invalide']); break;
    }
    $nom = trim(mb_substr((string)($input['nom'] ?? ''), 0, 60));
    $prenom = trim(mb_substr((string)($input['prenom'] ?? ''), 0, 40));
    $res = withLock($scoresFile, function (&$board, &$write) use ($name, $code4, $nom, $prenom) {
      foreach ($board as $p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          if ((string)($p['code'] ?? '') === $code4) {
            return ['ok' => true, 'exist' => true, 'name' => $p['name'],
                    'quiz_fait' => ($p['quiz_fait'] ?? true), 'justeprix_fait' => !empty($p['justeprix_fait']), 'recoltees' => round(floatval($p['score'] ?? 0), 1),
                    'solde' => soldeDe($p), 'nbCodes' => intval($p['codes'] ?? 0)];
          }
          return ['pris' => true];
        }
      }
      $board[] = ['name' => $name, 'code' => $code4, 'nom' => $nom, 'prenom' => $prenom, 'score' => 0, 'bonus' => 0,
        'depensees' => 0, 'correct' => 0, 'codes' => 0, 'codes_pris' => [], 'time' => 0,
        'quiz_fait' => false, 'date' => date('c')];
      $write = true;
      return ['ok' => true, 'exist' => false, 'name' => $name, 'quiz_fait' => false,
              'recoltees' => 0, 'solde' => 0, 'nbCodes' => 0];
    });
    if (!empty($res['pris'])) {
      http_response_code(409); echo json_encode(['error' => 'nom_pris']); break;
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  /* ---------- CONNEXION AVEC SON COMPTE FAMIFORMATION ---------- */

  // 🔑 Se connecter : identifiant OU email + mot de passe Famiformation.
  // Le quiz ne connaît aucun mot de passe : il demande à la base de l'app.
  case 'login_fami': {
    $ident = trim((string) ($input['identifiant'] ?? ''));
    $mdp   = (string) ($input['mdp'] ?? '');
    if ($ident === '' || $mdp === '') { echo json_encode(['ok' => false, 'reason' => 'vide']); break; }
    $db = famiDb();
    if (!$db) { http_response_code(503); echo json_encode(['ok' => false, 'reason' => 'base_indisponible']); break; }
    // ⚠️ REQUÊTE PROTÉGÉE. PDO est en mode exception : sans ce try, la moindre
    // erreur SQL (colonne absente, table verrouillée, connexion coupée en cours)
    // faisait planter la réponse. Le navigateur recevait alors du HTML d'erreur
    // au lieu du JSON attendu, n'y comprenait rien, et affichait
    // « Identifiant ou mot de passe incorrect » — un diagnostic entièrement faux,
    // qui envoyait le joueur vérifier un mot de passe parfaitement valide.
    //
    // La recherche accepte aussi l'identifiant écrit avec une autre casse, et
    // l'adresse e-mail dans n'importe quelle casse : personne ne doit rester
    // dehors pour une majuscule.
    try {
      $stmt = $db->prepare('SELECT id, identifiant, prenom, nom, email, mot_de_passe, account_activation_pending
                            FROM utilisateurs
                            WHERE LOWER(identifiant) = ? OR LOWER(email) = ? LIMIT 1');
      $stmt->execute([mb_strtolower($ident), mb_strtolower($ident)]);
      $u = $stmt->fetch();
    } catch (Throwable $eLog) {
      // On le DIT au lieu d'accuser le mot de passe.
      error_log('[quiz] login_fami : erreur base — ' . $eLog->getMessage());
      http_response_code(503);
      echo json_encode(['ok' => false, 'reason' => 'base_indisponible']);
      break;
    }
    if (!$u) { echo json_encode(['ok' => false, 'reason' => 'inconnu']); break; }
    // Compte créé mais jamais activé : inutile de parler de mot de passe, il n'en
    // a pas encore — on le renvoie vers son mail.
    if (!empty($u['account_activation_pending']) || empty($u['mot_de_passe'])) {
      echo json_encode(['ok' => false, 'reason' => 'a_activer']); break;
    }
    if (!password_verify($mdp, (string) $u['mot_de_passe'])) {
      echo json_encode(['ok' => false, 'reason' => 'mauvais_mdp']); break;
    }
    // Première partie ? On lui ouvre sa fiche de joueur. La clé reste le champ
    // `name` (= son identifiant Famiformation), ce qui garde tout le reste du
    // quiz inchangé : codes bonus, jardin, classement.
    $fiche = withLock($scoresFile, function (&$board, &$write) use ($u) {
      foreach ($board as &$p) {
        if (mb_strtolower((string) ($p['name'] ?? '')) === mb_strtolower((string) $u['identifiant'])) {
          $p['uid'] = (int) $u['id'];
          $p['prenom'] = $u['prenom']; $p['nom'] = $u['nom'];
          $write = true;
          return ['quiz_fait' => ($p['quiz_fait'] ?? true), 'justeprix_fait' => !empty($p['justeprix_fait']), 'recoltees' => round(floatval($p['score'] ?? 0), 1),
                  'solde' => soldeDe($p), 'nbCodes' => intval($p['codes'] ?? 0), 'pseudo' => ($p['pseudo'] ?? '')];
        }
      }
      unset($p);
      $board[] = ['name' => $u['identifiant'], 'uid' => (int) $u['id'], 'nom' => $u['nom'], 'prenom' => $u['prenom'],
        'score' => 0, 'bonus' => 0, 'depensees' => 0, 'correct' => 0, 'codes' => 0, 'codes_pris' => [],
        'time' => 0, 'quiz_fait' => false, 'date' => date('c')];
      $write = true;
      return ['quiz_fait' => false, 'recoltees' => 0, 'solde' => 0, 'nbCodes' => 0, 'pseudo' => ''];
    });
    echo json_encode(['ok' => true, 'jeton' => faitJeton($u['id'], $u['identifiant']),
      'joueur' => ['name' => $u['identifiant'], 'uid' => (int) $u['id'],
                   'prenom' => $u['prenom'], 'nom' => $u['nom']] + $fiche], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 👤 « C'est bien moi » : au chargement de la page, le navigateur renvoie son
  // jeton et on lui rend son état de jeu. Un jeton trafiqué ou périmé est rejeté
  // — c'est ça qui empêche de se faire passer pour quelqu'un d'autre.
  case 'moi': {
    $j = litJeton($input['jeton'] ?? '');
    if (!$j) { echo json_encode(['ok' => false, 'reason' => 'jeton_invalide']); break; }
    $board = readJson($scoresFile);
    foreach ($board as $p) {
      if (mb_strtolower((string) ($p['name'] ?? '')) === mb_strtolower($j['identifiant'])) {
        echo json_encode(['ok' => true, 'joueur' => [
          'name' => $p['name'], 'uid' => intval($p['uid'] ?? 0),
          'prenom' => $p['prenom'] ?? '', 'nom' => $p['nom'] ?? '', 'pseudo' => $p['pseudo'] ?? '',
          'quiz_fait' => ($p['quiz_fait'] ?? true), 'justeprix_fait' => !empty($p['justeprix_fait']), 'recoltees' => round(floatval($p['score'] ?? 0), 1),
          'solde' => soldeDe($p), 'nbCodes' => intval($p['codes'] ?? 0),
        ]], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
    // 🌱 PREMIÈRE ARRIVÉE DEPUIS FAMIFORMATION (tuile « Quiz & mon espace jardin »).
    // Aucune fiche de joueur n'existe encore. On répondait « inconnu », et la page
    // redemandait alors identifiant + mot de passe — à quelqu'un qui vient
    // justement d'un espace où il est DÉJÀ connecté. Le jeton est signé par notre
    // serveur : l'uid et l'identifiant qu'il porte sont sûrs. On ouvre donc sa
    // fiche ici, exactement comme login_fami le fait à la première connexion.
    //
    // Si la base est indisponible on répond « inconnu » comme avant : on ne casse
    // rien, la personne peut encore passer par la connexion classique.
    $dbMoi = famiDb();
    if (!$dbMoi) { echo json_encode(['ok' => false, 'reason' => 'inconnu']); break; }
    try {
      $stMoi = $dbMoi->prepare('SELECT id, identifiant, prenom, nom FROM utilisateurs WHERE id = ? LIMIT 1');
      $stMoi->execute([(int) $j['uid']]);
      $uMoi = $stMoi->fetch();
    } catch (Throwable $eMoi) {
      error_log('[quiz] moi : erreur base — ' . $eMoi->getMessage());
      echo json_encode(['ok' => false, 'reason' => 'inconnu']);
      break;
    }
    // Le compte doit exister ET porter l'identifiant du jeton : un jeton vit 60
    // jours, le compte a pu être renommé ou supprimé entre-temps.
    if (!$uMoi || mb_strtolower((string) $uMoi['identifiant']) !== mb_strtolower($j['identifiant'])) {
      echo json_encode(['ok' => false, 'reason' => 'inconnu']);
      break;
    }
    $ficheMoi = withLock($scoresFile, function (&$board, &$write) use ($uMoi) {
      // Relecture sous verrou : deux onglets ouverts en même temps ne doivent pas
      // créer deux fiches pour la même personne.
      foreach ($board as &$p) {
        if (mb_strtolower((string) ($p['name'] ?? '')) === mb_strtolower((string) $uMoi['identifiant'])) {
          return ['quiz_fait' => ($p['quiz_fait'] ?? true), 'justeprix_fait' => !empty($p['justeprix_fait']), 'recoltees' => round(floatval($p['score'] ?? 0), 1),
                  'solde' => soldeDe($p), 'nbCodes' => intval($p['codes'] ?? 0), 'pseudo' => ($p['pseudo'] ?? '')];
        }
      }
      unset($p);
      $board[] = ['name' => $uMoi['identifiant'], 'uid' => (int) $uMoi['id'], 'nom' => $uMoi['nom'], 'prenom' => $uMoi['prenom'],
        'score' => 0, 'bonus' => 0, 'depensees' => 0, 'correct' => 0, 'codes' => 0, 'codes_pris' => [],
        'time' => 0, 'quiz_fait' => false, 'date' => date('c')];
      $write = true;
      return ['quiz_fait' => false, 'recoltees' => 0, 'solde' => 0, 'nbCodes' => 0, 'pseudo' => ''];
    });
    echo json_encode(['ok' => true, 'joueur' => ['name' => $uMoi['identifiant'], 'uid' => (int) $uMoi['id'],
      'prenom' => $uMoi['prenom'], 'nom' => $uMoi['nom']] + $ficheMoi], JSON_UNESCAPED_UNICODE);
    break;
  }

  // ✉️ Pas encore de compte : prénom + nom + email. On crée le compte
  // Famiformation et le mail « Définir mon mot de passe » part DANS LA SECONDE.
  // Le lien reste valable $ACTIVATION_HEURES (assez pour s'inscrire bien avant
  // l'événement et n'activer que le jour J).
  case 'inscription_fami': {
    $prenom = trim(mb_substr((string) ($input['prenom'] ?? ''), 0, 40));
    $nom    = trim(mb_substr((string) ($input['nom'] ?? ''), 0, 60));
    $email  = mb_strtolower(trim((string) ($input['email'] ?? '')));
    if (mb_strlen($prenom) < 2 || mb_strlen($nom) < 2) {
      echo json_encode(['ok' => false, 'reason' => 'nom_manquant']); break;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['ok' => false, 'reason' => 'email_invalide']); break;
    }
    if (envoiRefuse($email)) { http_response_code(429); echo json_encode(['ok' => false, 'reason' => 'trop_dessais']); break; }

    $db = famiDb();
    if (!$db) { http_response_code(503); echo json_encode(['ok' => false, 'reason' => 'base_indisponible']); break; }

    $stmt = $db->prepare('SELECT id, mot_de_passe, account_activation_pending FROM utilisateurs WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $deja = $stmt->fetch();
    if ($deja) {
      // Compte déjà là : soit il est actif (qu'il se connecte), soit il attend
      // toujours son activation (on lui renvoie le mail).
      if (empty($deja['account_activation_pending']) && !empty($deja['mot_de_passe'])) {
        echo json_encode(['ok' => false, 'reason' => 'deja_inscrit']); break;
      }
      $envoye = sendAccountActivationEmail($db, (int) $deja['id'], $ACTIVATION_HEURES);
      echo json_encode(['ok' => (bool) $envoye, 'renvoye' => true,
        'reason' => $envoye ? null : 'mail_impossible'], JSON_UNESCAPED_UNICODE);
      break;
    }

    // 🎟️ Le Google Form (onglet « recolte de mail ») ne sert PLUS à décider si on
    // envoie le mail : SEULE la base de données compte (ci-dessus). La feuille
    // sert juste au contrôle des tickets glace. On la complète donc pour cette
    // personne, MAIS sans créer de doublon : on n'ajoute pas une ligne si son
    // prénom+nom (ou son e-mail) y est déjà, ou si elle est déjà en base.
    $fc = fluxCache();
    $cleNom = clePrenomNom($prenom, $nom);
    $connuNomBase = false;
    try {
      $qn = $db->prepare('SELECT 1 FROM utilisateurs WHERE LOWER(TRIM(prenom)) = ? AND LOWER(TRIM(nom)) = ? LIMIT 1');
      $qn->execute([mb_strtolower($prenom), mb_strtolower($nom)]);
      $connuNomBase = ($qn->fetchColumn() !== false);
    } catch (Throwable $e) { /* colonnes/table absentes en test */ }
    $dansFeuille = isset($fc['emails'][$email]) || isset($fc['noms'][$cleNom]);
    // ⚠️ On calcule ICI s'il faut compléter la feuille (avant de créer le compte),
    // mais on ne POUSSE le formulaire qu'APRÈS création + 1er mail (plus bas).
    // Sinon la soumission du form déclenche l'envoi auto AVANT que l'e-mail
    // existe en base → 2e mail. En poussant après, l'e-mail est déjà connu, donc
    // form_nouveau répond « déjà présent » et n'envoie rien.
    $aPousserVersForm = (!$dansFeuille && !$connuNomBase);

    // 🏬 Le magasin où la personne s'inscrit → son `site_id` dans la base, pour
    // pouvoir distinguer les inscrits de Mouscron de ceux de La Panne. On lit
    // l'id réel dans la table des sites de l'app (plutôt que de le supposer).
    $siteId = null;
    try {
      $qs = $db->prepare('SELECT id FROM widget_sites WHERE ville = ? LIMIT 1');
      $qs->execute([$SITES[$SITE]['ville']]);
      $trouve = $qs->fetchColumn();
      if ($trouve !== false) { $siteId = (int) $trouve; }
    } catch (Throwable $e) { /* table absente en test : on laissera site_id à NULL */ }

    try {
      ensureUserAccountAccessColumns($db);
      $identifiant = identifiantLibre($db, $prenom, $nom);
      $ins = $db->prepare('INSERT INTO utilisateurs (identifiant, nom, prenom, email, mot_de_passe, role, account_activation_pending, site_id, statut_date)
                           VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
      $ins->execute([$identifiant, $nom, $prenom, $email,
        password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), roleInscription($prenom, $nom), $siteId, date('Y-m-d H:i:s')]);
      $uid = (int) $db->lastInsertId();
    } catch (Throwable $e) {
      http_response_code(500); echo json_encode(['ok' => false, 'reason' => 'creation_impossible']); break;
    }

    // Comme dans l'admin de l'app : un compte sans mail parti ne sert à rien, on
    // ne laisse pas de compte fantôme derrière nous.
    if (!sendAccountActivationEmail($db, $uid, $ACTIVATION_HEURES)) {
      try { $db->prepare('DELETE FROM utilisateurs WHERE id = ?')->execute([$uid]); } catch (Throwable $e) {}
      http_response_code(500); echo json_encode(['ok' => false, 'reason' => 'mail_impossible']); break;
    }
    // ✅ Compte créé + 1er mail parti. MAINTENANT seulement on complète la feuille
    // Google (contrôle tickets glace). Comme l'e-mail existe déjà en base, si la
    // soumission déclenche l'envoi auto, il verra « déjà présent » → aucun 2e mail.
    if ($aPousserVersForm) { pousseVersForm($prenom, $nom, $email); }

    // 📧 LA PANNE : la collecte des adresses ne passe PAS par le Google Form,
    // mais par la table lapanne_emails que /emails/lapanne/ affiche aux RH
    // (avec le suivi des tickets remis). Une inscription faite depuis la BORNE
    // ou le TÉLÉPHONE doit donc y atterrir elle aussi — sans ça, les RH ne
    // verraient que les saisies faites a la main et rateraient tous
    // ceux qui se sont inscrits par le quiz.
    if ($SITE === 'lapanne') { lapanneCollecte(famiDb(), $prenom, $nom, $email); }

    // 📊 Trace de l'inscription, avec l'écran d'où elle vient.
    borneEvenement(famiDb(), $SITE, ecranDe($input), 'inscription', $prenom . ' ' . $nom);

    // Compte créé + mail envoyé (le seul cas « déjà donné ton mail » vient de la
    // base : compte en attente → on renvoie le lien, géré plus haut).
    echo json_encode(['ok' => true, 'identifiant' => $identifiant], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🔁 « Je n'ai pas reçu le mail » : on renvoie le lien d'activation.
  case 'renvoyer_activation': {
    $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['ok' => false, 'reason' => 'email_invalide']); break;
    }
    if (envoiRefuse($email)) { http_response_code(429); echo json_encode(['ok' => false, 'reason' => 'trop_dessais']); break; }
    $db = famiDb();
    if (!$db) { http_response_code(503); echo json_encode(['ok' => false, 'reason' => 'base_indisponible']); break; }
    $stmt = $db->prepare('SELECT id, mot_de_passe, account_activation_pending FROM utilisateurs WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    // On ne dit jamais si l'adresse est connue ou non : ça éviterait de pouvoir
    // deviner qui a un compte. Le message affiché est le même dans tous les cas.
    if ($u && (!empty($u['account_activation_pending']) || empty($u['mot_de_passe']))) {
      sendAccountActivationEmail($db, (int) $u['id'], $ACTIVATION_HEURES);
    }
    echo json_encode(['ok' => true]);
    break;
  }

  // 📣 ENVOI GROUPÉ (admin) : pour les gens déjà inscrits sur le formulaire mais
  // qui n'ont pas encore de compte. On leur crée le compte (si besoin) et on leur
  // envoie le mail « fun » d'invitation à créer leur compte AVANT le lancement.
  // On traite un LOT (max 25) par appel : l'admin envoie par tranches (pas de
  // timeout SMTP) et voit la progression.
  case 'envoi_groupe': {
    exigeAdmin($input);
    $db = famiDb();
    if (!$db) { http_response_code(503); echo json_encode(['ok' => false, 'reason' => 'base_indisponible']); break; }
    $siteId = siteIdCourant($db);

    $liste = is_array($input['liste'] ?? null) ? $input['liste'] : [];
    $liste = array_slice($liste, 0, 25);   // un lot à la fois
    $res = ['cree' => 0, 'renvoye' => 0, 'deja_present' => 0, 'echec' => 0];
    foreach ($liste as $p) {
      $etat = traiteInscritGroupe($db, $p, $siteId, $ACTIVATION_HEURES, false);
      if (isset($res[$etat])) { $res[$etat]++; } else { $res['echec']++; }
    }
    echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 👁️ APERÇU du formulaire (admin) : combien de personnes dans l'onglet
  // « recolte de mail », combien sont déjà dans le site (prénom+nom), combien
  // restent à contacter. Ne renvoie AUCUNE adresse au navigateur.
  case 'form_apercu': {
    exigeAdmin($input);
    $flux = litFluxFormulaire();
    if (!$flux['ok']) { echo json_encode(['ok' => false, 'reason' => $flux['reason']]); break; }
    $db = famiDb();
    if (!$db) { http_response_code(503); echo json_encode(['ok' => false, 'reason' => 'base_indisponible']); break; }
    if (function_exists('ensureUserAccountAccessColumns')) { ensureUserAccountAccessColumns($db); }
    $total = count($flux['lignes']);
    $dejaSite = 0;
    $qn = $db->prepare('SELECT id FROM utilisateurs WHERE (LOWER(TRIM(prenom)) = ? AND LOWER(TRIM(nom)) = ?) OR email = ? LIMIT 1');
    foreach ($flux['lignes'] as $p) {
      $qn->execute([mb_strtolower($p['prenom']), mb_strtolower($p['nom']), $p['email']]);
      if ($qn->fetchColumn() !== false) { $dejaSite++; }
    }
    echo json_encode(['ok' => true, 'total' => $total, 'deja' => $dejaSite, 'a_contacter' => max(0, $total - $dejaSite)], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 📣 ENVOI GROUPÉ DEPUIS LE FORMULAIRE (admin) : lit l'onglet « recolte de
  // mail », écarte les gens déjà dans le site (prénom+nom), et crée le compte +
  // envoie l'invitation aux autres. Par tranches : le client rappelle avec
  // ?debut=… jusqu'à ce que 'fini' soit vrai (pas de timeout SMTP).
  case 'envoi_groupe_form': {
    exigeAdmin($input);
    $flux = litFluxFormulaire();
    if (!$flux['ok']) { echo json_encode(['ok' => false, 'reason' => $flux['reason']]); break; }
    $db = famiDb();
    if (!$db) { http_response_code(503); echo json_encode(['ok' => false, 'reason' => 'base_indisponible']); break; }
    $siteId = siteIdCourant($db);

    $LOT = 20;
    $total = count($flux['lignes']);
    $debut = max(0, (int) ($input['debut'] ?? 0));
    $tranche = array_slice($flux['lignes'], $debut, $LOT);
    $res = ['cree' => 0, 'renvoye' => 0, 'deja_present' => 0, 'echec' => 0];
    foreach ($tranche as $p) {
      $etat = traiteInscritGroupe($db, $p, $siteId, $ACTIVATION_HEURES, true);  // contrôle par prénom+nom
      if (isset($res[$etat])) { $res[$etat]++; } else { $res['echec']++; }
    }
    $suivant = $debut + count($tranche);
    echo json_encode(['ok' => true, 'total' => $total, 'suivant' => $suivant, 'fini' => ($suivant >= $total)] + $res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // ⚡ NOUVELLE RÉPONSE AU FORMULAIRE (temps réel) : appelé par le déclencheur
  // onFormSubmit du script Google à CHAQUE envoi. Le script transmet prénom/nom/
  // e-mail + le secret. Si la personne n'a pas encore de compte (contrôle
  // prénom+nom), on lui crée son compte Mouscron et on envoie son lien.
  // Protégé par le secret (FORM_FEED_SECRET) et limité par adresse (anti-abus).
  case 'form_nouveau': {
    $secret = (string) ($input['secret'] ?? $_GET['secret'] ?? '');
    if ($FORM_FEED_SECRET === '' || !hash_equals($FORM_FEED_SECRET, $secret)) {
      http_response_code(401); echo json_encode(['ok' => false, 'reason' => 'secret']); break;
    }
    // 🔒 Interrupteur : tant que l'admin n'a pas activé l'envoi automatique, on
    // ne fait RIEN (aucun compte créé, aucun mail envoyé).
    if (!autoEnvoiActif()) { echo json_encode(['ok' => true, 'etat' => 'desactive']); break; }
    $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['ok' => false, 'reason' => 'email_invalide']); break;
    }
    // Garde-fou anti-abus : même avec un secret deviné, impossible de marteler
    // une adresse (le journal d'envois bloque au-delà du seuil).
    if (envoiRefuse($email)) { http_response_code(429); echo json_encode(['ok' => false, 'reason' => 'trop_dessais']); break; }
    $db = famiDb();
    if (!$db) { http_response_code(503); echo json_encode(['ok' => false, 'reason' => 'base_indisponible']); break; }

    // Le formulaire ne concerne que Mouscron → site_id de Mouscron.
    $siteId = null;
    try {
      $qs = $db->prepare('SELECT id FROM widget_sites WHERE ville = ? LIMIT 1');
      $qs->execute([$SITES['mouscron']['ville']]);
      $v = $qs->fetchColumn();
      if ($v !== false) { $siteId = (int) $v; }
    } catch (Throwable $e) { /* table absente en test */ }

    $etat = traiteInscritGroupe($db, [
      'prenom' => (string) ($input['prenom'] ?? ''),
      'nom'    => (string) ($input['nom'] ?? ''),
      'email'  => $email,
    ], $siteId, $ACTIVATION_HEURES, true, false);   // contrôle prénom+nom ; PAS de 2e mail si e-mail déjà connu (anti-doublon borne)

    echo json_encode(['ok' => in_array($etat, ['cree', 'renvoye', 'deja_present'], true), 'etat' => $etat], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🔒 Lire / changer l'interrupteur de l'envoi AUTOMATIQUE (admin). Sans champ
  // 'actif' → simple lecture de l'état. Avec 'actif' → on l'allume/éteint.
  case 'form_auto': {
    exigeAdmin($input);
    if (array_key_exists('actif', $input)) { autoEnvoiDefinir((bool) $input['actif']); }
    echo json_encode(['ok' => true, 'actif' => autoEnvoiActif()], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🎁 PAGE RH — LISTE DES RÉCOMPENSES à remettre (pour le magasin courant).
  // Deux sections : le PODIUM (top 3 par graines) et le JARDIN TERMINÉ (grille
  // pleine + les 3 lotus). Chaque personne a un état « remis » (coché par les RH).
  case 'rh_liste': {
    exigeRh($input);
    // 📜 Range une fois pour toutes les envois d'avant la séparation, qui sont
    // tous des mails « jardin ». Sans quoi ils s'affichaient aussi au podium.
    migreCompteursVersJardin();
    $board   = readJson($scoresFile);
    $jardins = readJson($jardinFile);
    $remises = readJson($rhFile);
    $remisPodium = (is_array($remises) && isset($remises['podium']) && is_array($remises['podium'])) ? $remises['podium'] : [];
    $remisJardin = (is_array($remises) && isset($remises['jardin']) && is_array($remises['jardin'])) ? $remises['jardin'] : [];

    $parNom = [];
    foreach ((is_array($board) ? $board : []) as $p) {
      if (is_array($p)) { $parNom[mb_strtolower((string) ($p['name'] ?? ''))] = $p; }
    }
    // 📧 Les adresses ne sont PAS dans le fichier des scores : on va les chercher
    // dans les comptes Famiformation, en UNE seule requête pour tout le monde.
    // Les RH en ont besoin pour recontacter un gagnant qui ne se présente pas, et
    // pour voir d'un coup d'œil qui n'a pas d'adresse — donc qui ne recevra jamais
    // le mail « viens chercher ta récompense ».
    // ⚠️ AUCUN ACCÈS À LA BASE ICI. Cette action sert AUSSI à se connecter : le
    // formulaire RH l'appelle pour valider le mot de passe. Tout ce qui peut
    // échouer ici empêche donc les RH d'ENTRER, pas seulement d'afficher une
    // colonne. Les adresses sont récupérées à part, par l'action rh_adresses,
    // dont l'échec ne prive que d'une information secondaire.
    //
    // Les compteurs d'envoi, eux, viennent du fichier RH déjà lu : aucun risque.
    $compteurs = (is_array($remises) && isset($remises['mails']) && is_array($remises['mails'])) ? $remises['mails'] : [];

    // $motif = 'podium' ou 'jardin' : on ne montre QUE les mails de cette
    // récompense-là. Les comptes d'avant la séparation n'ont pas de motif ; on
    // les remonte à part plutôt que de les attribuer au hasard à une section.
    $ligne = function ($p, $cle, $motif) use ($compteurs) {
      $tout = is_array($compteurs[$cle] ?? null) ? $compteurs[$cle] : [];
      $c = is_array($tout[$motif] ?? null) ? $tout[$motif] : [];
      return [
        'name'   => (string) ($p['name'] ?? $cle),
        'pseudo' => (string) ($p['pseudo'] ?? ''),
        'prenom' => (string) ($p['prenom'] ?? ''),
        'nom'    => (string) ($p['nom'] ?? ''),
        'score'  => round(floatval($p['score'] ?? 0), 1),
        'nb_attente' => (int) ($c['attente'] ?? 0),
        'nb_prete'   => (int) ($c['prete'] ?? 0),
        'dernier'    => (string) ($c['dernier'] ?? ''),
        // « auto » : le tout premier mail est parti tout seul (jardin terminé ou
        // podium du 31/08). Sans cette information, on ne pouvait pas savoir si
        // la personne était déjà au courant sans avoir rien fait.
        'origine'    => (string) ($c['origine'] ?? ''),
        'motif'      => $motif,
      ];
    };

    // 🏆 Podium : top 3 par score (quiz fait, hors comptes de test).
    $joueurs = [];
    foreach ((is_array($board) ? $board : []) as $p) {
      if (!is_array($p)) { continue; }
      if (($p['quiz_fait'] ?? true) === false) { continue; }
      if (estCompteTest($p)) { continue; }
      $joueurs[] = $p;
    }
    usort($joueurs, function ($a, $b) {
      $d = floatval($b['score'] ?? 0) - floatval($a['score'] ?? 0);
      if ($d > 0) { return 1; } if ($d < 0) { return -1; }
      return intval($a['time'] ?? 0) - intval($b['time'] ?? 0);
    });
    $podium = [];
    $rang = 1;
    foreach (array_slice($joueurs, 0, 3) as $p) {
      $cle = mb_strtolower((string) ($p['name'] ?? ''));
      $podium[] = $ligne($p, $cle, 'podium') + ['rang' => $rang, 'remis' => !empty($remisPodium[$cle]), 'test' => false];
      $rang++;
    }

    // 🧪 COMPTES DE TEST (admin_…), AJOUTÉS EN FIN DE PODIUM.
    //
    // Ils sont exclus du classement juste au-dessus — ils ne prennent donc
    // AUCUNE place et ne décalent personne : le podium reste le vrai podium.
    // On les affiche quand même ici, marqués « TEST », pour pouvoir essayer
    // l'envoi d'un mail sans écrire à un vrai gagnant. Leur rang vaut 0 :
    // c'est ce qui dit à la page RH de les afficher autrement.
    foreach ((is_array($board) ? $board : []) as $p) {
      if (!is_array($p) || ($p['quiz_fait'] ?? true) === false) { continue; }
      if (!estCompteTest($p)) { continue; }
      $cle = mb_strtolower((string) ($p['name'] ?? ''));
      $podium[] = $ligne($p, $cle, 'podium') + ['rang' => 0, 'remis' => !empty($remisPodium[$cle]), 'test' => true];
    }

    // 🎁 Jardin terminé : grille pleine + les 3 lotus (or, argent, bronze).
    $jardin = [];
    if (is_array($jardins)) {
      foreach ($jardins as $cle => $cases) {
        if (!is_array($cases)) { continue; }
        $nb = count($cases);
        $lotus = [];
        foreach ($cases as $c) {
          $pl = is_array($c) ? (string) ($c['plante'] ?? '') : '';
          if (in_array($pl, $LOTUS_REQUIS, true)) { $lotus[$pl] = true; }
        }
        if ($nb >= $JARDIN_CASES && count($lotus) >= count($LOTUS_REQUIS)) {
          $p = $parNom[$cle] ?? ['name' => $cle];
          // 🧪 Le compte d'essai apparaît ici aussi — marqué, comme au podium.
          $jardin[] = $ligne($p, $cle, 'jardin') + ['remis' => !empty($remisJardin[$cle]),
                                          'test' => estCompteTest($cle)];
        }
      }
    }
    usort($jardin, function ($a, $b) {
      // Les comptes d'essai toujours EN DERNIER : ce ne sont pas des gagnants,
      // ils n'ont rien à faire au milieu de la liste des récompenses à remettre.
      if (!empty($a['test']) !== !empty($b['test'])) { return !empty($a['test']) ? 1 : -1; }
      $na = trim($a['prenom'] . ' ' . $a['nom']); if ($na === '') { $na = $a['name']; }
      $nb = trim($b['prenom'] . ' ' . $b['nom']); if ($nb === '') { $nb = $b['name']; }
      return strcasecmp($na, $nb);
    });

    echo json_encode(['ok' => true, 'podium' => $podium, 'jardin' => $jardin], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🎁 Cocher / décocher « récompense remise » pour une personne (RH).
  case 'rh_cocher': {
    exigeRh($input);
    $type = (($input['type'] ?? '') === 'podium') ? 'podium' : 'jardin';
    $cle  = mb_strtolower(trim((string) ($input['name'] ?? '')));
    if ($cle === '') { echo json_encode(['ok' => false, 'reason' => 'nom_manquant']); break; }
    $remis = !empty($input['remis']);
    withLock($rhFile, function (&$data, &$write) use ($type, $cle, $remis) {
      if (!is_array($data)) { $data = []; }
      if (!isset($data[$type]) || !is_array($data[$type])) { $data[$type] = []; }
      if ($remis) { $data[$type][$cle] = 1; } else { unset($data[$type][$cle]); }
      $write = true;
    });
    echo json_encode(['ok' => true, 'remis' => $remis]);
    break;
  }

  // ✉️ Prévenir par MAIL les vainqueurs (podium) + jardins terminés : message
  // simple « viens récupérer ta récompense auprès des RH ». On ne renvoie qu'une
  // fois par personne (suivi dans rh-<site>.json['prevenu']).
  // 📧 ADRESSES DES GAGNANTS — action SÉPARÉE, appelée après l'affichage.
  // Volontairement hors de rh_liste : celle-ci sert à se connecter, et une base
  // injoignable ne doit pas fermer la porte des RH. Ici, un échec ne coûte que
  // l'affichage des adresses.
  case 'rh_adresses': {
    exigeRh($input);
    $ids = array_values(array_filter(array_map(
      function ($x) { return mb_strtolower(trim((string) $x)); },
      (array) ($input['ids'] ?? [])
    )));
    $sortie = [];
    try {
      if (!empty($ids) && count($ids) <= 400) {
        $dbA = famiDb();
        if ($dbA) {
          $trous = implode(',', array_fill(0, count($ids), '?'));
          $qa = $dbA->prepare("SELECT LOWER(identifiant) AS ident, email FROM utilisateurs WHERE LOWER(identifiant) IN ($trous)");
          $qa->execute($ids);
          foreach ($qa->fetchAll(PDO::FETCH_ASSOC) as $lig) {
            $sortie[(string) $lig['ident']] = trim((string) ($lig['email'] ?? ''));
          }
        }
      }
    } catch (Throwable $eA) {
      error_log('[quiz] rh_adresses : ' . $eA->getMessage());
    }
    echo json_encode(['ok' => true, 'adresses' => $sortie], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🎟️ L'ÉTAT DU STOCK ET LES BONS DÉJÀ DONNÉS.
//
// Action séparée de rh_liste, volontairement : rh_liste sert AUSSI à valider le
// mot de passe RH, et tout ce qui peut échouer dedans empêche d'ENTRER. Ici, une
// base indisponible ne prive que d'un onglet.
//
// Pas de filtre par magasin : le stock est commun aux deux, et un bon donné à
// Mouscron reste un bon en moins pour La Panne. Le magasin est affiché sur
// chaque ligne, pour s'y retrouver sans découper les chiffres.
case 'rh_recompenses': {
  exigeRh($input);
  $dbr = famiDb();
  if (!($dbr instanceof PDO)) {
    echo json_encode(['ok' => false, 'reason' => 'base_indisponible']);
    break;
  }
  recompenseCodesEnsure($dbr);
  $libres = 0; $donnes = 0; $lignes = [];
  // 🧪 Le compte de test est exclu du décompte ET de la liste : ce ne sont pas
  // des personnes à qui on a remis un bon. Depuis recompenseCodeTest() il ne
  // consomme plus de code du tout, ce filtre ne sert donc qu'aux lignes de
  // tests laissées par l'ancienne version.
  // LEFT(...) = 'admin_' plutôt que LIKE : dans un LIKE, le « _ » est un
  // joker qui matcherait « admin7 », « adminX »… La comparaison est
  // insensible à la casse par la collation, comme le stripos côté PHP.
  $saufTest = "LEFT(attribue_a, 6) <> 'admin_'";
  try {
    $libres = (int) $dbr->query('SELECT COUNT(*) FROM recompense_codes WHERE attribue_a IS NULL')->fetchColumn();
    $donnes = (int) $dbr->query(
      'SELECT COUNT(*) FROM recompense_codes WHERE attribue_a IS NOT NULL AND ' . $saufTest
    )->fetchColumn();
    $lignes = $dbr->query(
      'SELECT id, code_id, barcode, attribue_a, attribue_nom, attribue_email, attribue_le, motif, site
         FROM recompense_codes
        WHERE attribue_a IS NOT NULL AND ' . $saufTest . '
        ORDER BY attribue_le DESC, id DESC
        LIMIT 500'
    )->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { /* onglet vide plutôt qu'une erreur */ }
  echo json_encode(['ok' => true, 'libres' => $libres, 'donnes' => $donnes, 'lignes' => $lignes], JSON_UNESCAPED_UNICODE);
  break;
}

// 🎟️ Remet un bon dans le stock (attribué par erreur, mail jamais reçu…).
// La personne redevient éligible : c'est le SEUL moyen de lui renvoyer le mail,
// puisqu'un code déjà attribué bloque tout second envoi.
case 'rh_code_liberer': {
  exigeRh($input);
  $dbr = famiDb();
  $id = (int) ($input['id'] ?? 0);
  if (!($dbr instanceof PDO) || $id <= 0) { echo json_encode(['ok' => false]); break; }
  try {
    $dbr->prepare(
      'UPDATE recompense_codes
          SET attribue_a = NULL, attribue_nom = NULL, attribue_email = NULL,
              attribue_le = NULL, motif = NULL, site = NULL
        WHERE id = ?'
    )->execute([$id]);
    echo json_encode(['ok' => true]);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false]);
  }
  break;
}

case 'rh_mail': {
    exigeRh($input);
    $db = famiDb();
    if (!$db) { http_response_code(503); echo json_encode(['ok' => false, 'reason' => 'base_indisponible']); break; }
    $board   = readJson($scoresFile);
    $jardins = readJson($jardinFile);

    // Cibles : identifiant (minuscule) => ['type'=>podium|jardin, 'rang'=>?]
    $cibles = [];
    $joueurs = [];
    foreach ((is_array($board) ? $board : []) as $p) {
      if (!is_array($p)) { continue; }
      if (($p['quiz_fait'] ?? true) === false) { continue; }
      if (estCompteTest($p)) { continue; }
      $joueurs[] = $p;
    }
    usort($joueurs, function ($a, $b) {
      $d = floatval($b['score'] ?? 0) - floatval($a['score'] ?? 0);
      if ($d > 0) { return 1; } if ($d < 0) { return -1; }
      return intval($a['time'] ?? 0) - intval($b['time'] ?? 0);
    });
    // ⚠️ DEUX QUALIFICATIONS SÉPARÉES, et non une seule liste.
    //
    // Quelqu'un peut être sur le podium ET avoir terminé son jardin : ce sont
    // DEUX récompenses et DEUX mails. L'ancienne liste unique inscrivait la
    // personne au podium puis ignorait son jardin (« si pas déjà présente ») :
    // elle ne pouvait donc JAMAIS recevoir le mail du jardin. On qualifie
    // maintenant pour chaque récompense indépendamment ; la section d'où vient
    // la case cochée décide de celle qu'on envoie.
    $qualifPodium = [];
    $rang = 1;
    foreach (array_slice($joueurs, 0, 3) as $p) { $qualifPodium[mb_strtolower((string) $p['name'])] = ['type' => 'podium', 'rang' => $rang]; $rang++; }

    // 🧪 Les comptes d'essai vont dans une liste À PART : sans ça, un envoi
    // groupé leur écrirait alors qu'ils ne sont là que pour tester.
    $qualifJardin = [];
    $ciblesTest = [];
    if (is_array($jardins)) {
      foreach ($jardins as $cle => $cases) {
        if (!is_array($cases)) { continue; }
        $nb = count($cases); $lotus = [];
        foreach ($cases as $c) { $pl = is_array($c) ? (string) ($c['plante'] ?? '') : ''; if (in_array($pl, $LOTUS_REQUIS, true)) { $lotus[$pl] = true; } }
        if ($nb >= $JARDIN_CASES && count($lotus) >= count($LOTUS_REQUIS)) {
          if (estCompteTest($cle)) { $ciblesTest[$cle] = ['type' => 'jardin']; }
          else { $qualifJardin[$cle] = ['type' => 'jardin']; }
        }
      }
    }
    // Liste par défaut (envoi sans sélection) : le podium d'abord, puis les
    // jardins de ceux qui n'y sont pas — un seul mail par personne.
    $cibles = $qualifPodium;
    foreach ($qualifJardin as $cle => $info) { if (!isset($cibles[$cle])) { $cibles[$cle] = $info; } }

    // 🎯 QUI, ET QUEL MAIL. Les RH choisissent les personnes (cases à cocher) et
    // le modèle. Rien n'est plus envoyé « à tout le monde d'un coup ».
    //
    // Et on n'interdit PLUS le renvoi : un mail perdu dans les indésirables, une
    // adresse corrigée, une relance avant la fermeture — il faut pouvoir renvoyer.
    // On COMPTE les envois au lieu de les bloquer, ce qui informe sans empêcher.
    $modele = (($input['modele'] ?? '') === 'prete') ? 'prete' : 'attente';
    $demandes = array_values(array_filter(array_map(
      function ($x) { return mb_strtolower(trim((string) $x)); },
      (array) ($input['ids'] ?? [])
    )));
    // 🧪 Le compte d'essai peut recevoir le mail, pour le voir en vrai. Il n'est
    // ajouté QUE si on l'a explicitement coché : sans ça, un envoi « à tout le
    // monde » lui écrirait aussi.
    foreach ((is_array($board) ? $board : []) as $p) {
      if (!is_array($p) || ($p['quiz_fait'] ?? true) === false) { continue; }
      if (!estCompteTest($p)) { continue; }
      $cleT = mb_strtolower((string) $p['name']);
      // Rang 1 : le mail d'essai doit ressembler à celui d'un vrai gagnant.
      if (!isset($ciblesTest[$cleT])) { $ciblesTest[$cleT] = ['type' => 'podium', 'rang' => 1]; }
    }

    // 🎯 DEPUIS QUELLE SECTION LA CASE A-T-ELLE ÉTÉ COCHÉE ?
    //
    // C'est ce qui décide de la récompense dont on parle. Martine est au podium
    // ET a fini son jardin : cocher sa ligne du jardin doit envoyer le mail du
    // jardin, cocher celle du podium celui du podium. On ne peut évidemment pas
    // lui envoyer un mail de podium si elle n'y est pas : la section n'est
    // suivie que si la personne est bien qualifiée pour cette récompense-là.
    $sections = (array) ($input['sections'] ?? []);
    $section = function ($cle) use ($sections) {
      $s = mb_strtolower(trim((string) ($sections[$cle] ?? '')));
      return ($s === 'podium' || $s === 'jardin') ? $s : '';
    };

    if (!empty($demandes)) {
      $choisies = [];
      foreach ($demandes as $cle) {
        $sec = $section($cle);
        // Compte d'essai : il n'a rien gagné, on lui envoie la version demandée.
        if (isset($ciblesTest[$cle])) {
          $choisies[$cle] = ($sec === 'podium')
            ? ['type' => 'podium', 'rang' => 1]   // faux 1er, pour voir le mail du podium
            : (($sec === 'jardin') ? ['type' => 'jardin'] : $ciblesTest[$cle]);
          continue;
        }
        // Vrai gagnant : la section demandée si elle correspond à une
        // récompense qu'il a réellement obtenue, sinon celle qu'il a.
        if ($sec === 'podium' && isset($qualifPodium[$cle])) { $choisies[$cle] = $qualifPodium[$cle]; }
        elseif ($sec === 'jardin' && isset($qualifJardin[$cle])) { $choisies[$cle] = $qualifJardin[$cle]; }
        elseif (isset($qualifPodium[$cle])) { $choisies[$cle] = $qualifPodium[$cle]; }
        elseif (isset($qualifJardin[$cle])) { $choisies[$cle] = $qualifJardin[$cle]; }
      }
      $cibles = $choisies;
    }

    $res = ['envoye' => 0, 'deja' => 0, 'sans_mail' => 0, 'echec' => 0, 'modele' => $modele];
    $faits = [];
    foreach ($cibles as $cle => $info) {
      // Un seul endroit construit ces mails : mailRecompense(). L'ancien code
      // recopiait le texte ici, si bien qu'une correction devait être faite deux
      // fois — et ne l'était jamais.
      $ok = mailRecompense($db, $cle, $info, $modele, 'rh');
      if ($ok) {
        $res['envoye']++;
        $faits[] = $cle;
      } else {
        // mailRecompense renvoie false aussi bien pour une adresse absente que
        // pour un envoi rate : on distingue les deux, les RH n'ont pas le meme
        // geste a faire.
        $sansMail = true;
        try {
          $q = $db->prepare('SELECT email FROM utilisateurs WHERE LOWER(identifiant) = ? LIMIT 1');
          $q->execute([$cle]);
          $sansMail = !filter_var(trim((string) $q->fetchColumn()), FILTER_VALIDATE_EMAIL);
        } catch (Throwable $e) {}
        if ($sansMail) { $res['sans_mail']++; } else { $res['echec']++; }
      }
    }
    if ($faits) {
      withLock($rhFile, function (&$data, &$write) use ($faits, $modele) {
        if (!is_array($data)) { $data = []; }
        if (!isset($data['prevenu']) || !is_array($data['prevenu'])) { $data['prevenu'] = []; }
        // ⚠️ Le comptage des mails n'est PLUS fait ici : il l'est dans
        // mailRecompense(), donc pour les envois automatiques AUSSI. Le refaire
        // ici compterait chaque envoi RH deux fois.
        foreach ($faits as $c) {
          $data['prevenu'][$c] = 1;   // conservé : sert à l'envoi automatique
        }
        $write = true;
      });
    }
    echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🎁 Valider un code bonus (usage unique, premier arrivé premier servi).
  // « Premier arrivé premier servi » n'a de sens que si le test et la prise du
  // code sont indissociables : deux personnes qui scannent le MÊME QR code au
  // même moment doivent départager, pas gagner toutes les deux.
  case 'claim': {
    $code = strtoupper(trim($input['code'] ?? ''));
    $name = trim($input['name'] ?? '');
    if (!in_array($code, $BONUS_CODES, true)) {
      echo json_encode(['ok' => false, 'reason' => 'inconnu']);
      break;
    }

    $res = withLock($codesFile, function (&$claimed, &$write) use ($code, $name) {
      if (isset($claimed[$code])) {
        return ['ok' => false, 'reason' => 'deja_pris'];
      }
      $claimed[$code] = ['par' => $name, 'date' => date('c')];
      $write = true;
      return ['ok' => true];
    });

    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🌼 Le catalogue des plantes (public : affiché sur la page du jardin).
  case 'plantes': {
    echo json_encode(['plantes' => $PLANTES, 'cases' => $JARDIN_CASES], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🌳 La grille du jardin PERSONNEL d'un joueur (lecture seule). Chacun a la
  // sienne : le fichier est un dictionnaire pseudo(min) → { case → plante }.
  case 'jardin': {
    $name = mb_strtolower(trim($input['name'] ?? ''));
    $j = readJson($jardinFile);
    $cases = ($name !== '' && isset($j[$name]) && is_array($j[$name])) ? $j[$name] : [];
    echo json_encode(['cases' => (object)$cases], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 💰 Le solde d'un joueur qui revient (rechargement de page, autre appareil).
  case 'solde': {
    $name = mb_strtolower(trim($input['name'] ?? ''));
    $board = readJson($scoresFile);
    foreach ($board as $p) {
      if (mb_strtolower($p['name'] ?? '') === $name) {
        echo json_encode([
          'exists'    => true,
          'name'      => $p['name'],
          'recoltees' => round(floatval($p['score'] ?? 0), 1),
          'depensees' => intval($p['depensees'] ?? 0),
          'solde'     => soldeDe($p),
          'quiz_fait' => ($p['quiz_fait'] ?? true),
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
    echo json_encode(['exists' => false]);
    break;
  }

  // 🌱 Planter : débiter les graines PUIS poser la plante.
  // Deux fichiers sont touchés (scores + jardin) : on prend les verrous l'un
  // APRÈS l'autre, jamais imbriqués (deux verrous imbriqués pris dans des ordres
  // différents par deux requêtes = blocage mutuel). Si la case est prise entre
  // les deux étapes, on rembourse — le joueur ne perd jamais de graines pour rien.
  case 'planter': {
    $name   = trim($input['name'] ?? '');
    $idx    = intval($input['case'] ?? -1);
    $plante = trim($input['plante'] ?? '');

    if (!isset($PLANTES[$plante])) { echo json_encode(['ok' => false, 'reason' => 'plante_inconnue']); break; }
    if ($idx < 0 || $idx >= $JARDIN_CASES) { echo json_encode(['ok' => false, 'reason' => 'case_invalide']); break; }
    $cout = $PLANTES[$plante]['cout'];

    // Étape 1 : débit des graines, sous verrou des scores.
    $debit = withLock($scoresFile, function (&$board, &$write) use ($name, $cout) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          $solde = soldeDe($p);
          if ($solde < $cout) { return ['ok' => false, 'reason' => 'solde_insuffisant', 'solde' => $solde]; }
          $p['depensees'] = intval($p['depensees'] ?? 0) + $cout;
          $write = true;
          return ['ok' => true, 'solde' => $solde - $cout];
        }
      }
      return ['ok' => false, 'reason' => 'joueur_inconnu'];
    });
    if (empty($debit['ok'])) { echo json_encode($debit, JSON_UNESCAPED_UNICODE); break; }

    // Étape 2 : pose de la plante dans MON jardin (grille personnelle), sous verrou.
    $pose = withLock($jardinFile, function (&$j, &$write) use ($idx, $plante, $name) {
      $key = mb_strtolower($name);
      if (!isset($j[$key]) || !is_array($j[$key])) { $j[$key] = []; }
      if (isset($j[$key][$idx])) { return ['ok' => false, 'reason' => 'case_prise']; }
      $j[$key][$idx] = ['plante' => $plante, 'par' => $name, 'date' => date('c')];
      $write = true;
      return ['ok' => true];
    });

    if (empty($pose['ok'])) {
      // La case a été prise entre-temps : on rembourse le débit de l'étape 1.
      withLock($scoresFile, function (&$board, &$write) use ($name, $cout) {
        foreach ($board as &$p) {
          if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
            $p['depensees'] = max(0, intval($p['depensees'] ?? 0) - $cout);
            $write = true;
            break;
          }
        }
        return null;
      });
      echo json_encode($pose, JSON_UNESCAPED_UNICODE);
      break;
    }

    // 🎁 Le jardin vient-il d'être TERMINÉ (grille pleine + 3 lotus) ? Si oui et
    // pas déjà prévenu, on envoie AUTOMATIQUEMENT le mail « viens voir les RH ».
    // Les comptes de test (testeur/admin_) reçoivent AUSSI ce mail : c'est ce qui
    // permet à l'organisateur de tester l'envoi en jouant avec admin_. (Le mail
    // part vers l'adresse du compte, donc la sienne.) On ne bloque jamais la
    // plantation là-dessus.
    $cleNom = mb_strtolower($name);
    if (!dejaPrevenu($cleNom)) {
      $jTout = readJson($jardinFile);
      if (jardinEstComplet($jTout[$cleNom] ?? [])) {
        try {
          $dbR = famiDb();
          if ($dbR && mailRecompense($dbR, $cleNom, ['type' => 'jardin'])) { marquePrevenu($cleNom); }
        } catch (Throwable $e) { /* le mail est « au mieux », jamais bloquant */ }
      }
    }
    echo json_encode(['ok' => true, 'solde' => $debit['solde']], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 💸 REVENDRE une plante que J'AI plantée : la case se libère et je récupère
  // exactement ce que j'avais payé. On ne peut revendre QUE ses propres plantes
  // (vérifié par le prénom), pas celles des autres.
  case 'revendre': {
    $name = trim($input['name'] ?? '');
    $idx  = intval($input['case'] ?? -1);

    // Étape 1 : retirer la plante de MON jardin (c'est forcément la mienne), sous verrou.
    $retiree = withLock($jardinFile, function (&$j, &$write) use ($idx, $name) {
      $key = mb_strtolower($name);
      if (!isset($j[$key][$idx])) { return ['ok' => false, 'reason' => 'case_vide']; }
      $c = $j[$key][$idx];
      unset($j[$key][$idx]);
      $write = true;
      return ['ok' => true, 'plante' => $c['plante']];
    });
    if (empty($retiree['ok'])) { echo json_encode($retiree, JSON_UNESCAPED_UNICODE); break; }

    // Étape 2 : rembourser le coût (on diminue les graines dépensées), sous
    // verrou des scores. On renvoie le nouveau solde disponible.
    $cout = $PLANTES[$retiree['plante']]['cout'] ?? 0;
    $res = withLock($scoresFile, function (&$board, &$write) use ($name, $cout) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          $p['depensees'] = max(0, intval($p['depensees'] ?? 0) - $cout);
          $write = true;
          return ['ok' => true, 'solde' => soldeDe($p), 'rendu' => $cout];
        }
      }
      return ['ok' => true, 'solde' => null, 'rendu' => $cout];  // plante retirée quand même
    });
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🌿 RÉCOLTE DES MAUVAISES HERBES (mini-jeu) : la page envoie combien d'herbes
  // de chaque sorte ont été tapées ; le serveur RECALCULE les graines avec sa
  // propre table et plafonne le total, puis les crédite au « bonus » du joueur
  // (graines à planter, sans impact sur le classement).
  case 'recolte_herbes': {
    $name = trim($input['name'] ?? '');
    $h = is_array($input['herbes'] ?? null) ? $input['herbes'] : [];

    // Score du mini-jeu = herbes attrapées × leur valeur (normale 1, bronze 2,
    // argent 3, or 5). Ce score est ensuite CONVERTI en graines : 1 graine par
    // tranche de 10 points, plafonné à 10 graines par partie (ex. 10→1, 20→2,
    // 100→10, plus de 100 → 10).
    $score = 0;
    foreach ($HERBE_GAIN as $sorte => $valeur) {
      $n = max(0, min($HERBE_MAX_PAR_HERBE, intval($h[$sorte] ?? 0)));
      $score += $n * $valeur;
    }
    $gain = min($HERBE_MAX_GAIN, intdiv($score, 10));
    if ($gain <= 0) { echo json_encode(['ok' => false, 'reason' => 'rien']); break; }

    $res = withLock($scoresFile, function (&$board, &$write) use ($name, $gain) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          $p['bonus'] = intval($p['bonus'] ?? 0) + $gain;
          $write = true;
          return ['ok' => true, 'gain' => $gain, 'solde' => soldeDe($p)];
        }
      }
      return ['ok' => false, 'reason' => 'joueur_inconnu'];
    });
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🎯 QUIZ DU JARDIN : on a rejoué un quiz pour alimenter le jardin. Chaque bonne
  // réponse rapporte des graines de JARDIN (bonus), JAMAIS le classement. Authentifié
  // par le JETON (le nom vient du jeton, pas du client), plafonné par partie.
  case 'quiz_jardin': {
    $auth = litJeton($input['jeton'] ?? '');
    if (!$auth) { http_response_code(401); echo json_encode(['ok' => false, 'reason' => 'auth']); break; }
    $correct = max(0, min((int)($input['correct'] ?? 0), $QUIZ_JARDIN_MAX_BONNES));
    $gain = $correct * $QUIZ_JARDIN_PAR_BONNE;
    if ($gain <= 0) { echo json_encode(['ok' => false, 'reason' => 'rien']); break; }
    $name = $auth['identifiant'];
    $res = withLock($scoresFile, function (&$board, &$write) use ($name, $gain) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          $p['bonus'] = intval($p['bonus'] ?? 0) + $gain;
          $write = true;
          return ['ok' => true, 'gain' => $gain, 'solde' => soldeDe($p),
                  'recoltees' => round(floatval($p['score'] ?? 0), 1), 'nbCodes' => intval($p['codes'] ?? 0)];
        }
      }
      return ['ok' => false, 'reason' => 'inconnu'];
    });
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🏷️ LE JUSTE PRIX. Authentifié par le JETON : le nom vient du jeton, jamais
  // du client, sinon n'importe qui pourrait créditer n'importe quel compte.
  //
  // ⚠️ Le score est RECALCULÉ nulle part : contrairement au mini-jeu des herbes,
  // le barème du Juste Prix vit dans la page. On ne peut donc que le PLAFONNER.
  // C'est assumé pour un jeu bon enfant, mais ça veut dire qu'un joueur décidé
  // peut se donner 200 points au premier essai. Le plafond garantit au moins
  // qu'il ne peut pas s'en donner 10 000.
  case 'justeprix': {
    // L'épreuve n'est pas encore ouverte : on refuse AVANT tout le reste.
    if (!$JUSTEPRIX_OUVERT) { echo json_encode(['ok' => false, 'reason' => 'ferme']); break; }
    $auth = litJeton($input['jeton'] ?? '');
    if (!$auth) { http_response_code(401); echo json_encode(['ok' => false, 'reason' => 'auth']); break; }
    $points = max(0, min($JUSTEPRIX_MAX_PARTIE, round(floatval($input['points'] ?? 0), 1)));
    $name = $auth['identifiant'];

    $res = withLock($scoresFile, function (&$board, &$write) use ($name, $points, $JUSTEPRIX_REJEU_MAX) {
      for ($i = 0; $i < count($board); $i++) {
        if (mb_strtolower($board[$i]['name'] ?? '') !== mb_strtolower($name)) { continue; }

        // L'ordre des épreuves est une règle du jeu, pas seulement de l'affichage :
        // sans le quiz, pas de Juste Prix — même si quelqu'un appelait l'API à la main.
        if (empty($board[$i]['quiz_fait']) && !estCompteTest($board[$i])) {
          return ['ok' => false, 'reason' => 'quiz_dabord'];
        }

        // Le compte de test rejoue indéfiniment en 1re partie : il sert à vérifier.
        $premiere = empty($board[$i]['justeprix_fait']) || estCompteTest($board[$i]);

        if ($premiere) {
          // Classement + jardin : « score » alimente les deux.
          $board[$i]['score'] = round(floatval($board[$i]['score'] ?? 0) + $points, 1);
          if (!estCompteTest($board[$i])) { $board[$i]['justeprix_fait'] = true; }
          $gain = $points;
          $type = 'classement';
          sortBoard($board);   // le podium a pu bouger
        } else {
          // Rejeu : jardin seulement, et plafonné.
          $gain = min($JUSTEPRIX_REJEU_MAX, (int) floor($points));
          if ($gain > 0) { $board[$i]['bonus'] = intval($board[$i]['bonus'] ?? 0) + $gain; }
          $type = 'jardin';
        }

        $write = true;
        // Après sortBoard, l'index a pu changer : on relit par le nom.
        foreach ($board as $q) {
          if (mb_strtolower($q['name'] ?? '') === mb_strtolower($name)) {
            return ['ok' => true, 'type' => $type, 'gain' => $gain,
                    'plafond' => $JUSTEPRIX_REJEU_MAX,
                    'solde' => soldeDe($q), 'recoltees' => round(floatval($q['score'] ?? 0), 1)];
          }
        }
        return ['ok' => true, 'type' => $type, 'gain' => $gain, 'plafond' => $JUSTEPRIX_REJEU_MAX];
      }
      return ['ok' => false, 'reason' => 'inconnu'];
    });
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // ⏱ Les dates de l'événement (lues par la page joueur pour le compte à rebours).
  // On y joint la « version » du site (date du dernier déploiement de la page) :
  // la télé, qui reste allumée des jours entiers, s'en sert pour se recharger
  // toute seule après une mise en ligne au lieu de garder l'ancienne page.
  case 'config_get': {
    $conf = ladConfig($configFile, $SITE);
    $conf['version'] = (string) (@filemtime(__DIR__ . '/index.html') ?: 0);
    echo json_encode($conf, JSON_UNESCAPED_UNICODE);
    break;
  }

  // ⏱ Enregistrer les dates de l'événement (admin).
  case 'config_set': {
    exigeAdmin($input);
    $lancement = trim($input['lancement'] ?? '');
    $cloture   = trim($input['cloture'] ?? '');
    $resultats = trim(mb_substr((string)($input['resultats'] ?? ''), 0, 40));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $lancement)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'reason' => 'date_lancement_invalide']);
      break;
    }
    if ($cloture !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $cloture)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'reason' => 'date_cloture_invalide']);
      break;
    }
    $actuel = ladConfig($configFile, $SITE);
    // Zones (facultatif) : liste { nom, nb }. On nettoie et on plafonne à 30.
    $zones = $actuel['zones'];
    if (isset($input['zones']) && is_array($input['zones'])) {
      $zones = [];
      foreach (array_slice($input['zones'], 0, 30) as $z) {
        $nom = trim(mb_substr((string)($z['nom'] ?? ''), 0, 60));
        $nb  = max(0, intval($z['nb'] ?? 0));
        if ($nom !== '') { $zones[] = ['nom' => $nom, 'nb' => $nb]; }
      }
    }
    $recompenses = $actuel['recompenses'];
    if (isset($input['recompenses']) && is_array($input['recompenses'])) {
      $recompenses = [];
      foreach (array_slice($input['recompenses'], 0, 5) as $r) {
        $t = trim(mb_substr((string)$r, 0, 120));
        if ($t !== '') { $recompenses[] = $t; }
      }
    }
    writeJson($configFile, [
      'lancement' => $lancement,
      'cloture'   => $cloture !== '' ? $cloture : $actuel['cloture'],
      'resultats' => $resultats !== '' ? $resultats : $actuel['resultats'],
      'zones'     => $zones,
      'recompenses' => $recompenses,
    ]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🌱 RÉCUPÉRER SON COMPTE sur un autre téléphone : pseudo + code à 4 chiffres.
  // (Au quotidien, le téléphone reconnaît le joueur tout seul via son stockage
  // local ; cette action ne sert qu'au rattrapage.)
  case 'login_joueur': {
    // On se connecte avec SON PSEUDO **ou** SON NOM (l'un ou l'autre), + le mot de passe.
    $name  = trim($input['name'] ?? '');
    $code4 = preg_replace('/\D/', '', (string)($input['code'] ?? ''));
    $board = readJson($scoresFile);
    foreach ($board as $p) {
      // Les comptes Famiformation (sans code jardinier) ne se récupèrent PAS par
      // ce chemin : ils passent par la connexion Famiformation (login_fami).
      if ((string)($p['code'] ?? '') === '') { continue; }
      $parPseudo = mb_strtolower($p['name'] ?? '') === mb_strtolower($name);
      $complet   = trim(trim((string)($p['prenom'] ?? '')) . ' ' . trim((string)($p['nom'] ?? '')));
      $parNom    = $complet !== '' && mb_strtolower($complet) === mb_strtolower($name);
      if ($parPseudo || $parNom) {
        if ((string)($p['code'] ?? '') !== $code4) {
          echo json_encode(['exists' => true, 'mauvais_code' => true]);
          exit;
        }
        echo json_encode([
          'exists'    => true,
          'name'      => $p['name'],
          'recoltees' => round(floatval($p['score'] ?? 0), 1),
          'solde'     => soldeDe($p),
          'nbCodes'   => intval($p['codes'] ?? 0),
          'quiz_fait' => ($p['quiz_fait'] ?? true),
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }
    }
    echo json_encode(['exists' => false]);
    break;
  }

  // 🎁 STATUT d'un code bonus (quand on scanne son QR) : existe-t-il, est-il pris,
  // et — si on donne le pseudo — est-ce MOI qui l'ai déjà (pour un message adapté) ?
  case 'code_status': {
    $bonus = strtoupper(trim($input['bonuscode'] ?? ''));
    $name  = trim($input['name'] ?? '');
    // 🧪 Codes de test : réponse fixe (dispo / déjà utilisé), sans toucher aux données.
    if ($bonus === $CODE_TEST_USED) { echo json_encode(['connu' => true, 'pris' => true, 'parMoi' => false]); break; }
    if ($bonus === $CODE_TEST_OK)   { echo json_encode(['connu' => true, 'pris' => false, 'parMoi' => false]); break; }
    $connu = in_array($bonus, $BONUS_CODES, true);
    $pris  = false; $parMoi = false;
    if ($connu) {
      $claimed = readJson($codesFile);
      if (isset($claimed[$bonus])) {
        $pris = true;
        $parMoi = ($name !== '' && mb_strtolower($claimed[$bonus]['par'] ?? '') === mb_strtolower($name));
      }
    }
    echo json_encode(['connu' => $connu, 'pris' => $pris, 'parMoi' => $parMoi], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🎁 RÉCUPÉRER un code bonus et l'associer à son compte (+ graines).
  // Authentifié par pseudo + code à 4 chiffres. Usage unique (premier servi),
  // et maximum $MAX_CODES par personne. Les graines comptent dans le classement.
  case 'code_claim': {
    $name  = trim($input['name'] ?? '');
    $code4 = preg_replace('/\D/', '', (string)($input['code'] ?? ''));
    $bonus = strtoupper(trim($input['bonuscode'] ?? ''));

    if (!in_array($bonus, $BONUS_CODES, true)) { echo json_encode(['ok' => false, 'reason' => 'inconnu']); break; }
    // 🧪 Le code de test « déjà utilisé » refuse toujours.
    if ($bonus === $CODE_TEST_USED) { echo json_encode(['ok' => false, 'reason' => 'deja_pris']); break; }

    // Étape 1 : authentifier (jeton Famiformation ou code jardinier) + vérifier
    // qu'il peut encore prendre un code.
    $chk = withLock($scoresFile, function (&$board, &$write) use ($name, $bonus, $MAX_CODES, $input) {
      foreach ($board as $p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          if (!joueurAutorise($input, $name, $p['code'] ?? '')) { return ['ok' => false, 'reason' => 'auth']; }
          $pris = $p['codes_pris'] ?? [];
          if (in_array($bonus, $pris, true)) { return ['ok' => false, 'reason' => 'deja_a_toi']; }
          if (count($pris) >= $MAX_CODES) { return ['ok' => false, 'reason' => 'max_atteint', 'max' => $MAX_CODES]; }
          return ['ok' => true];
        }
      }
      return ['ok' => false, 'reason' => 'joueur_inconnu'];
    });
    if (empty($chk['ok'])) {
      if (($chk['reason'] ?? '') === 'auth' || ($chk['reason'] ?? '') === 'joueur_inconnu') { http_response_code(401); }
      echo json_encode($chk, JSON_UNESCAPED_UNICODE);
      break;
    }

    // Étape 2 : réserver le code globalement (premier arrivé, premier servi).
    // Le code de test « qui marche » n'est JAMAIS consommé : on saute cette étape
    // pour qu'il reste disponible et re-testable.
    if ($bonus !== $CODE_TEST_OK) {
      $prise = withLock($codesFile, function (&$claimed, &$write) use ($bonus, $name) {
        if (isset($claimed[$bonus])) { return ['ok' => false, 'reason' => 'deja_pris']; }
        $claimed[$bonus] = ['par' => $name, 'date' => date('c')];
        $write = true;
        return ['ok' => true];
      });
      if (empty($prise['ok'])) { echo json_encode($prise, JSON_UNESCAPED_UNICODE); break; }
    }

    // Étape 3 : créditer les graines (elles comptent dans le classement).
    $res = withLock($scoresFile, function (&$board, &$write) use ($name, $bonus, $CODE_GRAINES) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($name)) {
          $p['score'] = round(floatval($p['score'] ?? 0) + $CODE_GRAINES, 1);
          $p['codes_pris'] = array_values(array_merge($p['codes_pris'] ?? [], [$bonus]));
          $p['codes'] = count($p['codes_pris']);
          $write = true;
          return ['ok' => true, 'gagne' => $CODE_GRAINES, 'recoltees' => round(floatval($p['score']), 1), 'solde' => soldeDe($p), 'nbCodes' => $p['codes']];
        }
      }
      return ['ok' => true, 'gagne' => $CODE_GRAINES];
    });
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🚫 BLOQUER un code (admin) : il devient indisponible pour tout le monde, sans
  // etre attribue a un joueur (code perdu, carte abimee, retiree du magasin...).
  // 🔎 INDICE (cache) d'un code : l'organisateur note OÙ il a caché le code en
  // magasin, pour s'y retrouver (admin uniquement). Indice vide = on l'efface.
  case 'code_indice': {
    exigeAdmin($input);
    $bonus = strtoupper(trim($input['code'] ?? ''));
    if (!in_array($bonus, $BONUS_CODES, true)) { echo json_encode(['ok' => false, 'reason' => 'inconnu']); break; }
    $indice = mb_substr(trim((string) ($input['indice'] ?? '')), 0, 300);
    $tout = readJson($indicesFile);
    if (!is_array($tout)) { $tout = []; }
    if ($indice === '') { unset($tout[$bonus]); } else { $tout[$bonus] = $indice; }
    writeJson($indicesFile, $tout);
    echo json_encode(['ok' => true, 'indice' => $indice], JSON_UNESCAPED_UNICODE);
    break;
  }

  case 'code_bloquer': {
    exigeAdmin($input);
    $bonus = strtoupper(trim($input['code'] ?? ''));
    if (!in_array($bonus, $BONUS_CODES, true)) { echo json_encode(['ok' => false, 'reason' => 'inconnu']); break; }
    $res = withLock($codesFile, function (&$claimed, &$write) use ($bonus) {
      if (isset($claimed[$bonus])) { return ['ok' => false, 'reason' => 'deja_pris']; }
      $claimed[$bonus] = ['par' => 'Organisateur', 'date' => date('c'), 'bloque' => true];
      $write = true;
      return ['ok' => true];
    });
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🔓 LIBÉRER un code déjà attribué (admin) : le code redevient disponible pour
  // tout le monde, et la personne qui l'avait perd les graines correspondantes.
  // Deux verrous pris l'un APRÈS l'autre (jamais imbriqués).
  case 'code_liberer': {
    exigeAdmin($input);
    $bonus = strtoupper(trim($input['code'] ?? ''));
    if (!in_array($bonus, $BONUS_CODES, true)) {
      echo json_encode(['ok' => false, 'reason' => 'inconnu']); break;
    }
    // Étape 1 : retirer le code du registre (et retenir à qui il appartenait).
    $lib = withLock($codesFile, function (&$claimed, &$write) use ($bonus) {
      if (!isset($claimed[$bonus])) return ['ok' => false, 'reason' => 'pas_pris'];
      $par = $claimed[$bonus]['par'] ?? '';
      unset($claimed[$bonus]);
      $write = true;
      return ['ok' => true, 'par' => $par];
    });
    if (empty($lib['ok'])) { echo json_encode($lib, JSON_UNESCAPED_UNICODE); break; }

    // Étape 2 : le retirer au joueur et lui reprendre les graines du code.
    $par = (string)($lib['par'] ?? '');
    if ($par !== '') {
      withLock($scoresFile, function (&$board, &$write) use ($par, $bonus, $CODE_GRAINES) {
        for ($i = 0; $i < count($board); $i++) {
          if (mb_strtolower($board[$i]['name'] ?? '') === mb_strtolower($par)) {
            $pris = array_values(array_filter($board[$i]['codes_pris'] ?? [],
              fn($c) => strtoupper((string)$c) !== $bonus));
            $board[$i]['codes_pris'] = $pris;
            $board[$i]['codes'] = count($pris);
            $board[$i]['score'] = max(0, round(floatval($board[$i]['score'] ?? 0) - $CODE_GRAINES, 1));
            sortBoard($board);
            $write = true;
            break;
          }
        }
        return null;
      });
    }
    echo json_encode(['ok' => true, 'par' => $par, 'graines' => $CODE_GRAINES], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🧪 Créer (ou remettre à zéro) le COMPTE DE TEST sur le VRAI site, pour pouvoir
  // essayer le jeu depuis la borne, un téléphone ou un ordi. Pseudo « Testeur »,
  // mot de passe « 0000 ». Il n'apparaît jamais au classement public.
  case 'compte_test_creer': {
    exigeAdmin($input);
    $nomTest = 'Testeur'; $mdpTest = '0000';
    $res = withLock($scoresFile, function (&$board, &$write) use ($nomTest, $mdpTest) {
      for ($i = 0; $i < count($board); $i++) {
        if (mb_strtolower($board[$i]['name'] ?? '') === mb_strtolower($nomTest)) {
          $board[$i]['code'] = $mdpTest;
          $board[$i]['score'] = 0; $board[$i]['bonus'] = 0; $board[$i]['depensees'] = 0;
          $board[$i]['codes'] = 0; $board[$i]['codes_pris'] = []; $board[$i]['quiz_fait'] = false;
          $write = true;
          return ['ok' => true, 'remis' => true];
        }
      }
      $board[] = ['name' => $nomTest, 'code' => $mdpTest, 'nom' => 'Test', 'prenom' => 'Compte',
        'score' => 0, 'bonus' => 0, 'depensees' => 0, 'correct' => 0, 'codes' => 0,
        'codes_pris' => [], 'time' => 0, 'quiz_fait' => false, 'date' => date('c')];
      $write = true;
      return ['ok' => true, 'remis' => false];
    });
    echo json_encode(array_merge($res, ['pseudo' => $nomTest, 'mdp' => $mdpTest]), JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🔐 Connexion admin. La vérification se fait ICI, côté serveur : ainsi le mot
  // de passe n'apparaît PAS dans le code source de la page (contrairement à
  // l'ancien PIN, que n'importe qui pouvait lire avec « afficher la source »).
  case 'login': {
    $id  = trim($input['id'] ?? '');
    $pwd = (string)($input['pwd'] ?? '');
    $ok = hash_equals($ADMIN_ID, $id) && secretOk($ADMIN_PWD, $pwd);
    if (!$ok) {
      http_response_code(401);
      echo json_encode(['ok' => false]);
      break;
    }
    echo json_encode(['ok' => true]);
    break;
  }

  // ✉️ Les textes des récompenses (fenêtres + modalités), lus par la page joueur.
  // Public : ce sont des textes qu'elle affiche de toute façon.
  case 'messages': {
    echo json_encode(messagesActuels(), JSON_UNESCAPED_UNICODE);
    break;
  }

  // ✏️ L'onglet « Messages » de l'administration : la liste complète avec le
  // texte par défaut ET le texte personnalisé, pour pouvoir montrer les deux.
  case 'messages_admin': {
    exigeAdmin($input);
    $perso = readJson($messagesFile);   // ceux du magasin sélectionné dans l'admin
    $liste = [];
    foreach ($MESSAGES_DEFAUT as $cle => $def) {
      $liste[] = [
        'cle'     => $cle,
        'groupe'  => $def['groupe'],
        'libelle' => $def['libelle'],
        'trous'   => $def['trous'] ?? '',
        // Message tenant sur plusieurs lignes : l'admin lui donne un champ haut.
        'lignes'  => !empty($def['lignes']),
        'defaut'  => ['fr' => $def['fr'], 'nl' => $def['nl']],
        'perso'   => ['fr' => is_array($perso) ? (string) ($perso[$cle]['fr'] ?? '') : '',
                      'nl' => is_array($perso) ? (string) ($perso[$cle]['nl'] ?? '') : ''],
      ];
    }
    echo json_encode(['ok' => true, 'messages' => $liste], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 💾 Enregistrement des textes personnalisés. On ne garde QUE ce qui diffère
  // du texte d'origine : un champ vidé revient automatiquement au défaut, et le
  // fichier reste minuscule et lisible.
  case 'messages_save': {
    exigeAdmin($input);
    $recu = (array) ($input['messages'] ?? []);
    $propre = [];
    foreach ($MESSAGES_DEFAUT as $cle => $def) {
      foreach (['fr', 'nl'] as $lang) {
        if ($def[$lang] === null) { continue; }   // ce texte n'existe pas dans cette langue
        $v = trim((string) ($recu[$cle][$lang] ?? ''));
        if ($v === '' || $v === $def[$lang]) { continue; }
        $propre[$cle][$lang] = mb_substr($v, 0, 1500);
      }
    }
    writeJson($messagesFile, $propre);   // n'affecte QUE le magasin sélectionné
    echo json_encode(['ok' => true, 'modifies' => count($propre), 'site' => $SITE], JSON_UNESCAPED_UNICODE);
    break;
  }

  // ❓ Les questions du quiz (appelé par la page joueur au chargement).
  case 'questions': {
    echo json_encode(lesQuestions($questionsFile, $QUESTIONS_DEFAUT), JSON_UNESCAPED_UNICODE);
    break;
  }

  // ✏️ Enregistrer les questions (admin). Remplace la liste entière.
  case 'questions_save': {
    exigeAdmin($input);
    $propres = [];
    foreach ((array)($input['questions'] ?? []) as $item) {
      $c = nettoieQuestion($item);
      if ($c) { $propres[] = $c; }
    }
    if (!$propres) {
      http_response_code(400);
      echo json_encode(['error' => 'Il faut au moins une question valide (un intitulé et deux réponses).']);
      break;
    }
    writeJson($questionsFile, $propres);
    echo json_encode(['ok' => true, 'total' => count($propres)]);
    break;
  }

  // 🌱 CHARGER TOUTES LES QUESTIONS en un clic pour le magasin courant :
  //   • entreprise → les ~613 questions de la base Famiformation (table quiz_questions),
  //     + un petit fichier d'extras (ex. année de création), avec une réponse
  //     fausse rigolote ajoutée à chacune ;
  //   • culture   → seed/culture.json (jardinage) ;
  //   • fun       → seed/fun.json.
  // Écrit dans questions-<site>.json (à relancer pour chaque magasin).
  case 'questions_seed': {
    exigeAdmin($input);
    // 😄 La 4e proposition, toujours fausse et toujours drôle.
    //
    // Les questions de la base Famiformation n'ont que 3 réponses (A/B/C) : on en
    // ajoute une quatrième pour détendre. Elle doit rester DRÔLE POUR QUELQU'UN
    // QUI TRAVAILLE ICI — une blague qu'il faut expliquer n'est pas une blague.
    // « 42, évidemment » (clin d'œil au Guide du voyageur galactique) et « Google
    // le sait mieux que moi » ne parlaient à personne et revenaient une fois sur
    // quinze : elles sont remplacées par des vannes de terrain.
    //
    // ⚠️ Toujours EXACTEMENT le même nombre d'entrées ici et dans $RIGOLOTES_NL,
    // et dans le même ordre : la version NL est reprise par le même index.
    $RIGOLOTES = [
      "Rien du tout, on improvise 😅", "Demander à un collègue 🤷", "Appeler Jimmy 📞",
      "Comme d'habitude, au feeling 😎", "Ça dépend de la météo ☀️", "Un bon barbecue 🍖",
      "Aucune idée, mais ça sonne bien", "La même chose qu'hier", "Fermer les yeux et espérer 🤞",
      "C'est écrit nulle part, donc non", "Un café d'abord ☕", "Demander à la plante, elle sait 🌱",
      "On verra ça lundi", "Poser la question à l'accueil 🙋", "Sortir la brouette, on ne sait jamais 🛒",
      "Comme au marché de Noël : au feeling 🎄", "Faire semblant de ne pas avoir vu 🙈",
      "Attendre que quelqu'un d'autre le fasse 😴", "En parler à la pause ☕",
    ];
    // Version NL des mauvaises réponses rigolotes (même ordre que $RIGOLOTES).
    $RIGOLOTES_NL = [
      "Niets, we improviseren 😅", "Aan een collega vragen 🤷", "Jimmy bellen 📞",
      "Zoals altijd, op gevoel 😎", "Hangt af van het weer ☀️", "Een goeie barbecue 🍖",
      "Geen idee, maar het klinkt goed", "Hetzelfde als gisteren", "Ogen dicht en hopen 🤞",
      "Staat nergens, dus nee", "Eerst een koffie ☕", "Vraag het aan de plant, die weet het 🌱",
      "We zien wel maandag", "Vraag het aan het onthaal 🙋", "De kruiwagen halen, je weet maar nooit 🛒",
      "Zoals op de kerstmarkt: op gevoel 🎄", "Doen alsof je niets gezien hebt 🙈",
      "Wachten tot iemand anders het doet 😴", "Erover praten tijdens de pauze ☕",
    ];
    $lettreVersIndex = ['A' => 0, 'B' => 1, 'C' => 2];
    $tout = [];

    // 1) Entreprise : base Famiformation (quiz_questions) + réponse rigolote.
    $db = famiDb();
    $nbEntreprise = 0;
    if ($db) {
      try {
        // SELECT * : robuste si la table possède (ou non) des colonnes NL
        // (question_text_nl, option_a_nl…). Le jour où elles sont remplies dans
        // la base Famiformation, le NL des questions entreprise remonte tout seul.
        $rows = $db->query("SELECT * FROM quiz_questions ORDER BY theme ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
      } catch (Exception $e) { $rows = []; }
      $i = 0;
      foreach ($rows as $r) {
        $q = trim((string)($r['question_text'] ?? ''));
        $qNl = trim((string)($r['question_text_nl'] ?? ''));   // vide si la colonne n'existe pas
        $opts = [];
        $optsNl = [];
        foreach (['option_a', 'option_b', 'option_c'] as $col) {
          $o = trim((string)($r[$col] ?? ''));
          if ($o === '') { continue; }
          $opts[] = $o;
          $optsNl[] = trim((string)($r[$col . '_nl'] ?? ''));   // NL aligné (vide → fallback FR)
        }
        if ($q === '' || count($opts) < 2) { continue; }
        // Injouable sans le support ou la formation : on l'écarte du quiz.
        if (estQuestionExclue($q)) { continue; }
        // Y a-t-il une VRAIE traduction NL de la question (énoncé ou vraies réponses) ?
        // On regarde AVANT d'ajouter la réponse rigolote, pour ne pas afficher une seule
        // proposition en NL au milieu d'une question restée en FR.
        $aDuNlReel = ($qNl !== '') || count(array_filter($optsNl, static function ($x) { return $x !== ''; })) > 0;
        $lettre = strtoupper(trim((string)($r['reponse_correcte'] ?? 'A')));
        $correct = $lettreVersIndex[$lettre] ?? 0;
        if ($correct >= count($opts)) { $correct = 0; }
        $opts[] = $RIGOLOTES[$i % count($RIGOLOTES)];   // réponse fausse rigolote à la fin
        $item = ['q' => $q, 'options' => $opts, 'correct' => $correct, 'theme' => 'entreprise'];
        // ⭐ Favorites choisies PAR TEXTE. Les questions entreprise viennent de la
        // base Famiformation, qui n'a pas de colonne « favorite » : sans ça, une
        // étoile mise à la main dans /quiz/admin serait perdue à chaque
        // réinstallation des questions.
        if (estFavoriParTexte($q)) { $item['fav'] = true; }
        // 🧭 Contexte manquant : on remplace l'énoncé (voir
        // $QUESTIONS_RECONTEXTUALISEES). C'est fait ICI, après les tests
        // d'exclusion et de favorite : ceux-ci portent donc toujours sur le
        // texte d'origine tel qu'il est dans la base Famiformation.
        $recontexte = questionRecontextualisee($q);
        if ($recontexte !== null) {
          $item['q'] = $recontexte[0];
          // Le NL reformulé ne sert que si la question était déjà bilingue :
          // sinon on afficherait une question NL avec des réponses FR.
          if ($aDuNlReel && ($recontexte[1] ?? '') !== '') { $qNl = $recontexte[1]; }
        }
        // On ne passe en bilingue QUE si la question a une vraie trad NL : dans ce cas
        // seulement, on traduit aussi la réponse rigolote (pour rester 100 % cohérent).
        // Sinon la question entreprise reste entièrement en FR.
        if ($aDuNlReel) {
          $optsNl[] = $RIGOLOTES_NL[$i % count($RIGOLOTES_NL)];
          $item['q_nl'] = $qNl;
          $item['options_nl'] = $optsNl;
        }
        $i++;
        $tout[] = $item;
        $nbEntreprise++;
      }
    }

    // 2) Extras entreprise + culture + fun : fichiers livrés avec le code.
    foreach (['entreprise-extra.json' => 'entreprise', 'culture.json' => 'culture', 'fun.json' => 'fun'] as $fichier => $themeDefaut) {
      $chemin = __DIR__ . '/seed/' . $fichier;
      if (!is_file($chemin)) { continue; }
      $lus = json_decode((string)@file_get_contents($chemin), true);
      if (!is_array($lus)) { continue; }
      foreach ($lus as $item) {
        if (empty($item['theme'])) { $item['theme'] = $themeDefaut; }
        $tout[] = $item;
      }
    }

    // ✍️ Correction orthographique — UN SEUL endroit, juste avant la validation :
    // ça couvre d'un coup les questions de la base Famiformation ET celles des
    // fichiers. On ne touche QUE le français (`q` et `options`) : passer le
    // néerlandais dans une table d'accents français n'aurait aucun sens.
    foreach ($tout as &$brut) {
      if (isset($brut['q'])) { $brut['q'] = corrigeOrthographe($brut['q']); }
      if (!empty($brut['options']) && is_array($brut['options'])) {
        foreach ($brut['options'] as &$opt) { $opt = corrigeOrthographe($opt); }
        unset($opt);
      }
    }
    unset($brut);

    // Nettoyage/validation avec les mêmes règles que l'enregistrement manuel.
    $propres = [];
    foreach ($tout as $item) {
      $c = nettoieQuestion($item);
      if ($c) { $propres[] = $c; }
    }
    if (!$propres) {
      http_response_code(500);
      echo json_encode(['error' => 'Aucune question à charger (base indisponible et aucun fichier seed ?).']);
      break;
    }
    writeJson($questionsFile, $propres);
    $parTheme = ['entreprise' => 0, 'culture' => 0, 'fun' => 0];
    foreach ($propres as $p) { $t = $p['theme'] ?? 'entreprise'; if (isset($parTheme[$t])) $parTheme[$t]++; }
    echo json_encode(['ok' => true, 'total' => count($propres), 'entreprise' => $parTheme['entreprise'],
      'culture' => $parTheme['culture'], 'fun' => $parTheme['fun'], 'base_ok' => (bool)$db], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 📋 Tableau de bord admin : classement détaillé + état des codes + questions.
  case 'admin_data': {
    exigeAdmin($input);
    $board = readJson($scoresFile);
    sortBoard($board);
    $pris = readJson($codesFile);
    $indices = readJson($indicesFile);
    if (!is_array($indices)) { $indices = []; }
    $codes = [];
    foreach ($BONUS_CODES as $c) {
      if ($c === $CODE_TEST_OK || $c === $CODE_TEST_USED) { continue; }   // codes de test : hors liste
      $codes[] = [
        'code'   => $c,
        'pris'   => isset($pris[$c]),
        'par'    => $pris[$c]['par'] ?? null,
        'date'   => $pris[$c]['date'] ?? null,
        'indice' => $indices[$c] ?? '',                                   // 🔎 où il est caché
      ];
    }
    $j = readJson($jardinFile);
    echo json_encode([
      'board'     => $board,
      'codes'     => $codes,
      'questions' => lesQuestions($questionsFile, $QUESTIONS_DEFAUT),
      'jardin'    => ['cases' => (object)($j['cases'] ?? []), 'total' => $JARDIN_CASES],
      'plantes'   => $PLANTES,
      'config'    => ladConfig($configFile, $SITE),
    ], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🧹 Vider une case du jardin (admin) : la plante disparaît, le planteur est
  // remboursé de son coût — c'est une correction, pas une punition.
  case 'jardin_vider': {
    exigeAdmin($input);
    global $PLANTES;
    $idx = intval($input['case'] ?? -1);

    $retiree = withLock($jardinFile, function (&$j, &$write) use ($idx) {
      if (!isset($j['cases'][$idx])) { return null; }
      $c = $j['cases'][$idx];
      unset($j['cases'][$idx]);
      $write = true;
      return $c;
    });

    if (!$retiree) { echo json_encode(['ok' => false, 'reason' => 'case_vide']); break; }
    $cout = $PLANTES[$retiree['plante']]['cout'] ?? 0;
    withLock($scoresFile, function (&$board, &$write) use ($retiree, $cout) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === mb_strtolower($retiree['par'] ?? '')) {
          $p['depensees'] = max(0, intval($p['depensees'] ?? 0) - $cout);
          $write = true;
          break;
        }
      }
      return null;
    });
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🌟 PLANTER librement (admin) : l'organisateur pose la plante qu'il veut, où
  // il veut, gratuitement. Attribuée à « Famiflora 🌟 » (hors classement joueurs).
  case 'jardin_planter': {
    exigeAdmin($input);
    global $PLANTES, $JARDIN_CASES;
    $idx    = intval($input['case'] ?? -1);
    $plante = trim($input['plante'] ?? '');
    if (!isset($PLANTES[$plante])) { echo json_encode(['ok' => false, 'reason' => 'plante_inconnue']); break; }
    if ($idx < 0 || $idx >= $JARDIN_CASES) { echo json_encode(['ok' => false, 'reason' => 'case_invalide']); break; }
    $res = withLock($jardinFile, function (&$j, &$write) use ($idx, $plante) {
      if (!isset($j['cases']) || !is_array($j['cases'])) { $j['cases'] = []; }
      $j['cases'][$idx] = ['plante' => $plante, 'par' => 'Famiflora 🌟', 'date' => date('c')];
      $write = true;
      return ['ok' => true];
    });
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🧹 Réinitialiser tout le jardin (admin) : grille vidée, tout le monde
  // récupère l'intégralité de ses graines (depensees remis à zéro).
  case 'jardin_reset': {
    exigeAdmin($input);
    writeJson($jardinFile, ['cases' => (object)[]]);
    withLock($scoresFile, function (&$board, &$write) {
      foreach ($board as &$p) { $p['depensees'] = 0; }
      $write = count($board) > 0;
      return null;
    });
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🗑️ Retirer un participant du classement (erreur de prénom, test, doublon…).
  case 'player_delete': {
    exigeAdmin($input);
    $nom = trim($input['name'] ?? '');
    $res = withLock($scoresFile, function (&$board, &$write) use ($nom) {
      $avant = count($board);
      $board = array_values(array_filter($board, function ($p) use ($nom) {
        return mb_strtolower($p['name'] ?? '') !== mb_strtolower($nom);
      }));
      $write = count($board) !== $avant;
      return ['ok' => $write, 'board' => $board];
    });
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🌱 RESET du jardin d'UN joueur (admin, pour re-tester) : on vide ses cases,
  // on lui rembourse ses graines dépensées (depensees=0), et on efface son
  // marqueur « prévenu » pour que le mail récompense puisse repartir à la
  // prochaine complétion. Le classement (score) n'est PAS touché.
  case 'reset_jardin_joueur': {
    exigeAdmin($input);
    $cle = mb_strtolower(trim((string) ($input['name'] ?? '')));
    if ($cle === '') { echo json_encode(['ok' => false, 'reason' => 'nom_manquant']); break; }
    $vide = withLock($jardinFile, function (&$j, &$write) use ($cle) {
      if (is_array($j) && isset($j[$cle])) { unset($j[$cle]); $write = true; return true; }
      return false;
    });
    withLock($scoresFile, function (&$board, &$write) use ($cle) {
      foreach ($board as &$p) {
        if (mb_strtolower($p['name'] ?? '') === $cle) { $p['depensees'] = 0; $write = true; break; }
      }
      return null;
    });
    withLock($rhFile, function (&$data, &$write) use ($cle) {
      if (is_array($data) && isset($data['prevenu'][$cle])) { unset($data['prevenu'][$cle]); $write = true; }
    });
    echo json_encode(['ok' => true, 'vide' => (bool) $vide], JSON_UNESCAPED_UNICODE);
    break;
  }

  // 🧹 Réinitialiser (tests) : api.php?action=reset&pin=XXXX
  case 'reset': {
    if (!secretOk($ADMIN_PIN, (string)($_GET['pin'] ?? ''))) {
      http_response_code(403);
      echo json_encode(['error' => 'PIN incorrect']);
      break;
    }
    writeJson($scoresFile, []);
    writeJson($codesFile, (object)[]);
    writeJson($jardinFile, ['cases' => (object)[]]);
    echo json_encode(['ok' => true, 'message' => 'Scores, codes et jardin remis à zéro']);
    break;
  }

  default: {
    http_response_code(400);
    echo json_encode(['error' => 'Action inconnue']);
  }
}
