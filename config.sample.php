<?php
/**
 * Copie para config.php e preencha as URLs dos feeds .ics.
 * O config.php real fica só no servidor (contém o endereço SECRETO do Google).
 */
return [
    'title'       => 'Disponibilidade — Rafa',
    'subtitle'    => 'Horários livres (apenas livre/ocupado — compromissos ficam privados)',
    'tz'          => 'America/Sao_Paulo',

    // Fontes de calendário (qualquer nº). Chave = rótulo interno (não aparece pro público).
    'sources' => [
        'microsoft' => 'https://outlook.office365.com/owa/calendar/SEU-ID@suaempresa.com/SEU-HASH/calendar.ics',
        // Cole aqui o "Endereço secreto no formato iCal" do Google:
        'google'    => '',
    ],

    'work_days'    => [1, 2, 3, 4, 5], // 1=seg … 7=dom
    'work_start'   => '08:00',
    'work_end'     => '19:00',
    'horizon_days' => 14,              // próximas 2 semanas
    'min_slot_min' => 30,              // ignora vão livre menor que isso
    'cache_ttl'    => 900,             // segundos (15 min)
    'accent'       => '#6D4AFF',
];
