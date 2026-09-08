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

</body>

</html>