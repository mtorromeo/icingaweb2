<?php

namespace Icinga\Module\Dashboards\Form;

use Icinga\Module\Dashboards\Model\Database;
use ipl\Html\Html;
use ipl\Sql\Select;
use ipl\Web\Compat\CompatForm;

class DashletForm extends CompatForm
{
    use Database;
    private $data;

    public function fetchDashboards()
    {
        $dashboards = [];

        $select = (new Select())
            ->columns('*')
            ->from('dashboard');

        $rs = $this->getDb()->select($select);

        foreach ($rs as $dashboard) {
            $dashboards[$dashboard->id] = $dashboard->name;
        }

        return $dashboards;
    }

    public function newAction()
    {
        $this->setAction('dashboards/dashlets/new');

        $this->addElement('textarea', 'url', [
            'label' => 'Url',
            'placeholder' => 'Enter Dashlet Url',
            'required' => true,
            'rows' => '3'
        ]);

        $this->addElement('text', 'name', [
            'label' => 'Dashlet Name',
            'placeholder' => 'Enter Dashlet Name',
            'required' => true
        ]);

        $this->addElement('select', 'dashboard', [
            'label' => 'Dashboard',
            'required'  => true,
            'options' => $this->fetchDashboards()
        ]);

        $this->addElement('submit', 'submit', [
            'label' => 'Add To Dashboard'
        ]);
    }

    protected function assemble()
    {
        $this->add($this->newAction());
    }

    public function onSuccess()
    {
        $this->data = [
            'dashboard_id' => $this->getValue('dashboard'),
            'name'  => $this->getValue('name'),
            'url'   => $this->getValue('url'),
        ];

        $this->getDb()->insert('dashlet', $this->data);
    }
}
