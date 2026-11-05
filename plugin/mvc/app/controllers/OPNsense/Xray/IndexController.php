<?php

namespace OPNsense\Xray;

/**
 * Renders the main GUI page.
 * Passes both forms to the volt template.
 * Route: /ui/xray/  (matches Menu.xml url)
 */
class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        bindtextdomain('os-xray', '/usr/local/share/locale');
        bind_textdomain_codeset('os-xray', 'UTF-8');
        $this->view->xlang = new class {
            public function _(string $key): string
            {
                $text = dgettext('os-xray', $key);
                return view_html_safe($text === $key ? gettext($key) : $text);
            }
        };
        $previous = textdomain(null);
        textdomain('os-xray');
        try {
            $this->view->generalForm  = $this->getForm('general');
            $this->view->routingForm = $this->getForm('routing');
            $this->view->watchdogForm = $this->getForm('watchdog');
            $this->view->instanceForm = $this->getForm('instance');
        } finally {
            textdomain($previous);
        }
        $this->view->pick('OPNsense/Xray/general');
    }
}
