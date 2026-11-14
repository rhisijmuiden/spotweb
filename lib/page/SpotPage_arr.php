<?php

require_once __DIR__.'/../services/Nntp/Services_Nntp_ClientPool.php';
require_once __DIR__.'/../services/Nntp/Services_Nntp_SpotReader.php';

/*
 * JSON endpoints for Radarr and Sonarr:
 *   test     check an address and API key, return folders and profiles
 *   refresh  fetch the library again now
 *   lookup   search results for a spot, for the "Add to" dialog
 *   add      add the title the user picked
 *   ensure   add a spot's title when it is missing (used when the browser
 *            sends the download to SABnzbd itself)
 *
 * All actions are POSTs with an XSRF token (arrform[xsrfid]).
 */
class SpotPage_arr extends SpotPage_Abs
{
    private $_form;

    public function __construct(Dao_Factory $daoFactory, Services_Settings_Container $settings, array $currentSession, array $params)
    {
        parent::__construct($daoFactory, $settings, $currentSession);
        $this->_form = $params['arrform'];
    }

    public function render()
    {
        $this->sendExpireHeaders(true);
        header('Content-Type: application/json; charset=utf-8');

        $action = (string) ($this->_form['action'] ?? '');
        if ($action === '') {
            http_response_code(400);
            $this->answer(false, _('Your session expired. Reload the page and try again.'));

            return;
        } // if

        if (!Services_Arr_Config::siteEnabled($this->_settings)) {
            $this->answer(false, _('The administrator has switched off Radarr and Sonarr for this site.'));

            return;
        } // if
        if (!Services_Arr_Config::userMayUse($this->_currentSession)) {
            http_response_code(403);
            $this->answer(false, _('You do not have permission to use Radarr and Sonarr here.'));

            return;
        } // if

        try {
            switch ($action) {
                case 'test': $this->test(); break;
                case 'refresh': $this->refresh(); break;
                case 'lookup': $this->lookup(); break;
                case 'add': $this->add(); break;
                case 'ensure': $this->ensure(); break;
                default:
                    http_response_code(400);
                    $this->answer(false, 'Unknown action');
            } // switch
        } catch (Services_Arr_Exception $x) {
            $this->answer(false, $x->getMessage());
        } catch (Exception $x) {
            $this->answer(false, $x->getMessage());
        } // catch
    }

    private function answer($ok, $message, array $data = [])
    {
        echo json_encode(['ok' => (bool) $ok, 'message' => (string) $message] + $data);
    }

    private function service()
    {
        $service = (string) ($this->_form['service'] ?? '');
        if (!in_array($service, [Services_Arr_Client::RADARR, Services_Arr_Client::SONARR], true)) {
            throw new Services_Arr_Exception('Unknown service');
        } // if

        return $service;
    }

    /*
     * Test the address and key that are in the form now (saved or not).
     * An empty key field means: the key that is saved.
     */
    private function test()
    {
        $service = $this->service();
        $saved = Services_Arr_Config::forPrefs($this->_currentSession['user']['prefs'] ?? [])[$service];
        $url = trim((string) ($this->_form['url'] ?? ''));
        $key = trim((string) ($this->_form['apikey'] ?? ''));
        if ($key === '') {
            $key = Services_Arr_Config::decrypt($this->_settings, $saved['apikey']);
        } // if
        if (!Services_Arr_Client::isValidUrl($url)) {
            throw new Services_Arr_Exception(_('Enter the address, for example http://192.168.1.20:7878'));
        } // if

        $client = new Services_Arr_Client($service, $url, $key, 10);
        $status = $client->systemStatus();
        $app = (string) ($status['appName'] ?? '');
        $expected = ($service === Services_Arr_Client::RADARR) ? 'Radarr' : 'Sonarr';
        if ($app !== '' && strcasecmp($app, $expected) !== 0) {
            throw new Services_Arr_Exception(sprintf(_('This address is %s, not %s.'), $app, $expected));
        } // if

        $data = [
            'version'     => (string) ($status['version'] ?? ''),
            'rootfolders' => $client->rootFolders(),
            'profiles'    => $client->qualityProfiles(),
        ];
        if ($service === Services_Arr_Client::SONARR && strpos($data['version'], '3.') === 0) {
            $data['languageprofiles'] = $client->languageProfiles();
        } // if

        $this->answer(true, sprintf(_('Connected to %s %s.'), $expected, $data['version']), $data);
    }

    private function integration()
    {
        $integration = new Services_Arr_Integration($this->_settings, $this->_daoFactory, $this->_currentSession);
        if (!$integration->isActive()) {
            throw new Services_Arr_Exception(_('Set up Radarr or Sonarr under My account first.'));
        } // if

        return $integration;
    }

    private function refresh()
    {
        $integration = $this->integration();
        $services = isset($this->_form['service']) && $this->_form['service'] !== '' ? [$this->service()] : [Services_Arr_Client::RADARR, Services_Arr_Client::SONARR];
        $results = $integration->refreshServices($services, true);

        $messages = [];
        $ok = true;
        foreach ($results as $service => $r) {
            $name = ($service === Services_Arr_Client::RADARR) ? 'Radarr' : 'Sonarr';
            if ($r['ok']) {
                $messages[] = sprintf(($service === Services_Arr_Client::RADARR)
                    ? ngettext('%s: %d movie', '%s: %d movies', $r['items'])
                    : ngettext('%s: %d series', '%s: %d series', $r['items']), $name, $r['items']);
            } else {
                $ok = false;
                $messages[] = $name.': '.$r['message'];
            } // else
        } // foreach

        $this->answer($ok, implode(' · ', $messages), ['results' => $results]);
    }

    private function fullSpot()
    {
        $messageId = (string) ($this->_form['messageid'] ?? '');
        if ($messageId === '') {
            throw new Services_Arr_Exception('No spot');
        } // if
        $this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_view_spotdetail, '');

        $svcTextSpotReading = new Services_Nntp_SpotReader(Services_Nntp_ClientPool::pool($this->_settings, 'hdr'));
        $svcProvSpot = new Services_Providers_FullSpot($this->_daoFactory->getSpotDao(), $svcTextSpotReading);
        $fullSpot = $svcProvSpot->fetchFullSpot($messageId, $this->_currentSession['user']['userid']);
        if (empty($fullSpot)) {
            throw new Services_Arr_Exception(_('This spot could not be found.'));
        } // if

        return $fullSpot;
    }

    private function lookup()
    {
        $result = $this->integration()->lookup($this->fullSpot());
        $this->answer(true, empty($result['candidates']) ? _('Nothing found with this title.') : '', $result);
    }

    private function add()
    {
        $integration = $this->integration();
        $fullSpot = $this->fullSpot();
        $overrides = [];
        if (isset($this->_form['rootfolder']) && $this->_form['rootfolder'] !== '') {
            $overrides['rootfolder'] = mb_substr((string) $this->_form['rootfolder'], 0, 255);
        } // if
        if (isset($this->_form['profile']) && (int) $this->_form['profile'] > 0) {
            $overrides['profile'] = (int) $this->_form['profile'];
        } // if

        $result = $integration->addPicked($fullSpot, (string) ($this->_form['ref'] ?? ''), $overrides);
        $d = $integration->describe($fullSpot);
        $name = ($d['service'] === Services_Arr_Client::RADARR) ? 'Radarr' : 'Sonarr';
        $title = Services_Arr_Integration::displayTitle($result['item']);
        $this->answer(true, $result['added'] ? sprintf(_('Added %s to %s.'), $title, $name) : sprintf(_('%s is already in %s.'), $title, $name), [
            'view' => $integration->view($result['status']),
        ]);
    }

    private function ensure()
    {
        $integration = $this->integration();
        $fullSpot = $this->fullSpot();
        $result = $integration->ensure($fullSpot);
        $this->answer($result['ok'], $result['message'], ['added' => $result['added'], 'view' => $integration->view($result['status'])]);
    }
}
