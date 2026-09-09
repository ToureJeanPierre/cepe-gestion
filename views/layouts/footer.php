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

</body>

</html>