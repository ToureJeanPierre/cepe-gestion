        </main>


        <!-- ==================================================
             FOOTER
        =================================================== -->

        <footer
            class="border-top bg-white py-3 px-4"
        >

            <div
                class="d-flex justify-content-between align-items-center flex-wrap gap-2"
            >

                <div
                    class="text-muted"
                    style="font-size: 11px;"
                >

                    <strong>
                        Gestion CEPE
                    </strong>

                    —
                    IEPP Yopougon-Niangon

                </div>


                <div
                    class="text-muted"
                    style="font-size: 11px;"
                >

                    Année scolaire
                    <strong>
                        <?= htmlspecialchars($ANNEE_SCOLAIRE ?? 'N/A') ?>
                    </strong>

                </div>

            </div>

        </footer>


    </div>

</div>


<!-- Bootstrap JavaScript -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
></script>

<!--
    Correctif global : mémorise la position de défilement avant tout rechargement
    de page (filtre, suppression, etc.) et la restaure automatiquement, pour éviter
    de remonter en haut de page à chaque action. S'applique à toutes les pages.
-->
<script>
(function () {
    var cleScroll = 'scrollpos_' + window.location.pathname;

    window.addEventListener('beforeunload', function () {
        sessionStorage.setItem(cleScroll, window.scrollY);
    });

    document.addEventListener('DOMContentLoaded', function () {
        var positionSauvee = sessionStorage.getItem(cleScroll);
        if (positionSauvee !== null) {
            window.scrollTo(0, parseInt(positionSauvee, 10));
            sessionStorage.removeItem(cleScroll);
        }
    });
})();
</script>

<!--
    Menu mobile : ouvre/ferme la barre latérale en rideau sur petit écran
    (bouton hamburger dans la topbar, rideau sombre en arrière-plan, fermeture
    automatique au clic sur un lien ou au retour en largeur "bureau").
-->
<script>
(function () {
    var sidebar = document.getElementById('sidebarPrincipal');
    var rideau = document.getElementById('sidebarBackdrop');

    window.ouvrirMenuMobile = function () {
        sidebar.classList.add('is-open');
        rideau.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    };

    window.fermerMenuMobile = function () {
        sidebar.classList.remove('is-open');
        rideau.classList.remove('is-open');
        document.body.style.overflow = '';
    };

    sidebar.querySelectorAll('a.nav-link').forEach(function (lien) {
        lien.addEventListener('click', window.fermerMenuMobile);
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 767) {
            window.fermerMenuMobile();
        }
    });
})();
</script>

<!-- =========================================================
     BARRE DE DÉFILEMENT HORIZONTALE FIXE (tableaux larges)
     N'importe quelle page peut avoir un tableau trop large pour l'écran
     (beaucoup de colonnes). Plutôt que de forcer l'utilisateur à
     redescendre jusqu'au bas de CE tableau pour le décaler latéralement,
     cette barre reste visible en bas de l'écran quelle que soit la ligne
     consultée, et décale tous les tableaux marqués ".tableau-scrollable"
     de la page en même temps. Elle ne s'affiche que si l'un d'eux déborde
     réellement — invisible et sans effet sur les pages qui n'en ont pas.
========================================================== -->
<div id="barreDefilementGlobale" class="barre-defilement-globale">
    <div id="barreDefilementGlobaleInterieur"></div>
</div>

<style>
.barre-defilement-globale {
    display: none;
    position: fixed;
    bottom: 0;
    left: var(--sidebar-width, 260px);
    right: 0;
    height: 14px;
    overflow-x: auto;
    overflow-y: hidden;
    background: #f1f3f5;
    border-top: 1px solid #dee2e6;
    z-index: 1030;
}
.barre-defilement-globale #barreDefilementGlobaleInterieur {
    height: 1px;
}
body.a-barre-defilement-globale {
    padding-bottom: 18px;
}
@media (max-width: 767px) {
    .barre-defilement-globale { left: 0; }
}
</style>

<script>
(function () {
    var barre = document.getElementById('barreDefilementGlobale');
    var interieur = document.getElementById('barreDefilementGlobaleInterieur');
    var tableaux = Array.prototype.slice.call(document.querySelectorAll('.tableau-scrollable'));

    if (!tableaux.length) return;

    var enSynchronisation = false;

    function actualiser() {
        var largeurMax = tableaux.reduce(function (max, t) {
            return Math.max(max, t.scrollWidth);
        }, 0);
        var deborde = tableaux.some(function (t) { return t.scrollWidth > t.clientWidth + 1; });

        interieur.style.width = largeurMax + 'px';
        barre.style.display = deborde ? 'block' : 'none';
        document.body.classList.toggle('a-barre-defilement-globale', deborde);
    }

    function synchroniserDepuis(source, valeur) {
        if (enSynchronisation) return;
        enSynchronisation = true;
        if (barre !== source) barre.scrollLeft = valeur;
        tableaux.forEach(function (t) {
            if (t !== source) t.scrollLeft = valeur;
        });
        enSynchronisation = false;
    }

    barre.addEventListener('scroll', function () {
        synchroniserDepuis(barre, barre.scrollLeft);
    });

    tableaux.forEach(function (t) {
        t.addEventListener('scroll', function () {
            synchroniserDepuis(t, t.scrollLeft);
        });
    });

    actualiser();
    window.addEventListener('resize', actualiser);
})();
</script>

</body>

</html>