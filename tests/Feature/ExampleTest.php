<?php

it('redirige la racine vers le tableau de bord', function () {
    $this->get('/')->assertRedirect('/tableau-de-bord');
});
