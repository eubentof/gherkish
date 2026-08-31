<?php

use Gherkish\Gherkish;

it('User logs in', function (string $email, string $password, string $result) {
    /** @Given a user with email "<email>" */
    expect($email)->toEndWith('@test.com');

    /** @When they log in with password "<password>" */
    expect($password)->not->toBeEmpty();

    /** @Then the result should be "<result>" */
    expect($result)->toBeIn(['success', 'failure']);
})->with(Gherkish::examples());
