<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.4.0
 * @copyright   (C) 2026 web-service. All rights reserved.
 * @license     GNU/GPL
 *
 * Strony, które wtyczka pokazuje klientowi: przejście do PayU, oczekiwanie na
 * potwierdzenie płatności, brak zapłaty z ponowieniem, komunikat o błędzie.
 *
 * Plik jest wczytywany przez showPage(), więc $this wskazuje na obiekt wtyczki.
 * Można go nadpisać w szablonie: templates/<szablon>/hikashoppayment/payu_end.php
 *
 * Przyciski nigdy nie są wyłączane na czas przekierowania: gdy klient wróci ze strony
 * płatności przyciskiem „wstecz”, przeglądarka odtwarza stronę z pamięci razem ze stanem
 * pól i zostałby z szarym, nieklikalnym przyciskiem.
 */

use Joomla\CMS\Language\Text;

defined('_JEXEC') or die('Restricted access');

$redirectUrl  = (string) ($this->payu_redirect_url ?? '');
$errorText    = (string) ($this->payu_error ?? '');
$paidNotice   = (string) ($this->payu_paid_notice ?? '');
$unpaidNotice = (string) ($this->payu_unpaid_notice ?? '');
$retryUrl     = (string) ($this->payu_retry_url ?? '');
$waiting      = !empty($this->payu_waiting);
$statusUrl    = (string) ($this->payu_status_url ?? '');
$checkUrl     = (string) ($this->payu_check_url ?? '');

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$js     = static fn ($value): string => (string) json_encode(
    (string) $value,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<div class="hikashop_payu_end" id="hikashop_payu_end">
<?php if ($waiting) : ?>
    <div class="hikashop_payu_waiting" aria-live="polite">
        <h2><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_WAITING_TITLE')); ?></h2>
        <p><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_WAITING_INFO')); ?></p>
    <?php if ($checkUrl !== '') : ?>
        <p>
            <a class="btn btn-secondary hikashop_payu_check" href="<?php echo $escape($checkUrl); ?>" rel="nofollow">
                <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_CHECK_NOW')); ?>
            </a>
        </p>
    <?php endif; ?>
    </div>
    <?php if ($statusUrl !== '') : ?>
    <script type="text/javascript">
    (function () {
        'use strict';

        // Skrypt o niczym nie decyduje. Pyta sklep, a sklep czyta zamówienie z bazy
        // albo pyta PayU z serwera.
        var adresStanu = <?php echo $js($statusUrl); ?>;
        var adresSprawdzenia = <?php echo $js($checkUrl); ?>;

        var LIMIT_MS = 60000;
        var ODSTEP_MS = 2500;
        var PIERWSZE_PYTANIE_PAYU_MS = 4000;
        var ODSTEP_PAYU_MS = 8000;

        var poczatek = Date.now();
        var ostatniePytaniePayu = 0;
        var zakonczone = false;
        var wToku = false;

        function przejdz(adres) {
            if (zakonczone || !adres) {
                return;
            }

            zakonczone = true;
            window.location.href = adres;
        }

        function odczytaj(tekst) {
            // Wyłuskanie obiektu wyrażeniem chroni przed witryną, która coś do odpowiedzi dokleja.
            var trafienie = /\{"state":"[a-z]+"[^{}]*\}/.exec(tekst || '');

            if (!trafienie) {
                return null;
            }

            try {
                return JSON.parse(trafienie[0]);
            } catch (blad) {
                return null;
            }
        }

        function zaplanuj() {
            if (!zakonczone) {
                window.setTimeout(sprawdz, ODSTEP_MS);
            }
        }

        function sprawdz() {
            if (zakonczone || wToku) {
                return;
            }

            var minelo = Date.now() - poczatek;

            if (minelo > LIMIT_MS) {
                // Strona powrotu zapyta PayU ostatni raz i pokaże właściwy komunikat.
                przejdz(adresSprawdzenia);

                return;
            }

            var adres = adresStanu;

            if (minelo >= PIERWSZE_PYTANIE_PAYU_MS && Date.now() - ostatniePytaniePayu >= ODSTEP_PAYU_MS) {
                ostatniePytaniePayu = Date.now();
                adres += '&payu_ask=1';
            }

            wToku = true;

            var zadanie = new XMLHttpRequest();

            zadanie.open('GET', adres + '&_=' + Date.now(), true);
            zadanie.timeout = 25000;
            zadanie.onloadend = function () {
                wToku = false;

                var odpowiedz = zadanie.status === 200 ? odczytaj(zadanie.responseText) : null;

                if (odpowiedz && odpowiedz.redirect && odpowiedz.state !== 'waiting') {
                    przejdz(odpowiedz.redirect);

                    return;
                }

                // Błędny znacznik się nie naprawi, więc nie ma po co pytać dalej.
                if (odpowiedz && odpowiedz.state === 'invalid') {
                    zakonczone = true;

                    return;
                }

                zaplanuj();
            };
            zadanie.send();
        }

        window.addEventListener('pageshow', function (zdarzenie) {
            // Powrót „wstecz” odtwarza stronę z pamięci przeglądarki razem ze stanem
            // skryptu, więc zaczynamy od nowa.
            if (zdarzenie.persisted) {
                zakonczone = false;
                wToku = false;
                poczatek = Date.now();
                ostatniePytaniePayu = 0;
            }

            zaplanuj();
        });
    })();
    </script>
    <?php endif; ?>
<?php elseif ($paidNotice !== '') : ?>
    <div class="hikashop_payu_paid">
        <h2><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAID_TITLE')); ?></h2>
        <p><?php echo $escape($paidNotice); ?></p>
    </div>
<?php elseif ($unpaidNotice !== '') : ?>
    <?php // To nie jest podziękowanie: klient ma zobaczyć, że nie zapłacił, i móc zapłacić jeszcze raz. ?>
    <div class="hikashop_payu_unpaid">
        <h2><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_RETURN_UNPAID_TITLE')); ?></h2>
        <p><?php echo $escape($unpaidNotice); ?></p>
    <?php if ($retryUrl !== '') : ?>
        <p>
            <a class="btn btn-primary btn-lg hikashop_payu_retry" href="<?php echo $escape($retryUrl); ?>" rel="nofollow">
                <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAY_AGAIN')); ?>
            </a>
        </p>
    <?php elseif ($checkUrl !== '') : ?>
        <p>
            <a class="btn btn-secondary hikashop_payu_check" href="<?php echo $escape($checkUrl); ?>" rel="nofollow">
                <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_CHECK_NOW')); ?>
            </a>
        </p>
    <?php endif; ?>
        <p class="hikashop_payu_note"><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_ORDER_KEPT')); ?></p>
    </div>
<?php elseif ($errorText !== '') : ?>
    <div class="hikashop_payu_error">
        <h2><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAYMENT_NOT_STARTED')); ?></h2>
        <p><?php echo $escape($errorText); ?></p>
    <?php if ($retryUrl !== '') : ?>
        <p>
            <a class="btn btn-primary hikashop_payu_retry" href="<?php echo $escape($retryUrl); ?>" rel="nofollow">
                <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_PAY_AGAIN')); ?>
            </a>
        </p>
    <?php endif; ?>
        <p class="hikashop_payu_note"><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_ORDER_KEPT')); ?></p>
    </div>
<?php elseif ($redirectUrl !== '') : ?>
    <div class="hikashop_payu_redirect">
        <h2><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_GOING_TO_GATEWAY')); ?></h2>
        <p><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_GOING_TO_GATEWAY_INFO')); ?></p>
        <p>
            <a id="hikashop_payu_button" class="btn btn-primary btn-lg hikashop_payu_button" href="<?php echo $escape($redirectUrl); ?>" rel="nofollow">
                <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PAYU_GO_TO_GATEWAY')); ?>
            </a>
        </p>
    </div>
    <script type="text/javascript">
    (function () {
        'use strict';

        var adres = <?php echo $js($redirectUrl); ?>;
        var juzPrzekierowano = false;

        function przejdzDoPayu() {
            if (juzPrzekierowano) {
                return;
            }

            juzPrzekierowano = true;
            window.location.href = adres;
        }

        // Przekierowanie samoczynne tylko przy pierwszym wyświetleniu. Gdy klient wróci
        // „wstecz” ze strony płatności, strona pochodzi z pamięci przeglądarki i ma zostać
        // taka, jaka jest: z żywym przyciskiem, żeby mógł zdecydować sam.
        window.addEventListener('pageshow', function (zdarzenie) {
            if (zdarzenie.persisted) {
                juzPrzekierowano = false;

                return;
            }

            window.setTimeout(przejdzDoPayu, 400);
        });

        var przycisk = document.getElementById('hikashop_payu_button');

        if (przycisk) {
            przycisk.addEventListener('click', function () {
                juzPrzekierowano = true;
            });
        }
    })();
    </script>
<?php endif; ?>
</div>
