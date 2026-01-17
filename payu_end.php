<?php
/**
 * @package     HikaShop PayU Payment Plugin
 * @version     2.1.0
 * @copyright   (C) 2026 web-service. All rights reserved.
 * @license     GNU/GPL
 */

use Joomla\CMS\Language\Text;
use Joomla\CMS\Factory;

defined('_JEXEC') or die('Restricted access');
?>
<div class="hikashop_payu_end" id="hikashop_payu_end">
    <span id="hikashop_payu_end_message" class="hikashop_payu_end_message">
        <?php
        echo Text::sprintf(
            'PLEASE_WAIT_BEFORE_REDIRECTION_TO_X',
            htmlspecialchars($this->payment_name, ENT_QUOTES, 'UTF-8')
        ) . '<br/>' . Text::_('CLICK_ON_BUTTON_IF_NOT_REDIRECTED');
        ?>
    </span>
    <span id="hikashop_payu_end_spinner" class="hikashop_payu_end_spinner">
        <img src="<?php echo HIKASHOP_IMAGES . 'spinner.gif'; ?>" alt="Loading..." />
    </span>
    <br />
    <form id="hikashop_payu_form" name="hikashop_payu_form"
        action="<?php echo htmlspecialchars($this->payment_params->payment_url ?? '', ENT_QUOTES, 'UTF-8'); ?>"
        method="post">
        <div id="hikashop_payu_end_image" class="hikashop_payu_end_image">
            <input id="hikashop_payu_button" class="btn btn-primary" type="submit"
                value="<?php echo Text::_('PAY_NOW'); ?>" />
        </div>
        <?php
        if (!empty($this->vars) && is_array($this->vars)) {
            foreach ($this->vars as $name => $value) {
                echo '<input type="hidden" name="' . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '" />';
            }
        }

        // Auto-submit formularza
        $doc = Factory::getApplication()->getDocument();
        $doc->addScriptDeclaration(
            "window.hikashop.ready(function() { 
                const form = document.getElementById('hikashop_payu_form'); 
                if(form) { form.submit(); } 
            });"
        );

        // HikaShop internal flag
        hikaInput::get()->set('noform', 1);
        ?>
    </form>
</div>