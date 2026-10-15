<?php

use Gherkish\Gherkish;

it('Extract users', function () {
    /** @Given the following users exist: */
    $users = Gherkish::dataTable();

    /** @When the table is read */
    expect($users)->toHaveCount(3);

    /** @Then user values remain strings */
    expect($users[0])->toBe(['name' => 'Aslak', 'email' => 'aslak@cucumber.io', 'twitter' => '@aslak_hellesoy']);
});

it('Read a post body', function () {
    /** @Given a draft post */
    $full = Gherkish::docs();

    /** @When the title is selected */
    $body = Gherkish::docs('Some Title, Eh?', step: 'a draft post');

    /** @Then only the body is returned */
    expect($body)->toBe("Here is the first paragraph of my blog post. Lorem ipsum dolor sit amet,\nconsectetur adipiscing elit.")
        ->and($full)->toStartWith("Some Title, Eh?\n===============\n");
});

it('Read headerless cells', function () {
    /** @Given a shopping list */
    $rows = Gherkish::dataTable(raw: true);

    /** @When raw rows are requested */
    expect($rows)->toHaveCount(2);

    /** @Then every row is retained */
    expect($rows)->toBe([['eggs'], ['milk']]);
});

it('Preserve Doc String formatting', function () {
    /** @Given formatted content */
    $content = Gherkish::docs();

    /** @When all content is read */
    expect($content)->toContain("Given this is content\n  nested\n\n");

    /** @Then indentation and blank lines survive */
    expect($content)->toEndWith("@tests:\n- not-a-mapping.php\nScenario: also content");
});

it('Substitute argument values', function (string $email) {
    /** @Given an imported user */
    $users = Gherkish::dataTable(values: ['email' => $email]);

    /** @When a personalized message is read */
    $message = Gherkish::docs(values: ['email' => $email]);

    /** @Then both arguments use the selected row */
    expect($users)->toBe([['email' => $email, 'active' => '0']])->and($message)->toBe('Hello '.$email);
})->with(Gherkish::examples());

function readWrappedGherkishContent(): string
{
    return Gherkish::docs();
}

it('Resolve a wrapper call', function () {
    /** @Given wrapped content */
    $content = readWrappedGherkishContent();

    /** @When the wrapper returns */
    expect($content)->toBeString();

    /** @Then the caller annotation is used */
    expect($content)->toBe('wrapped');
});

it('Select an explicit step', function () {
    /** @Given first content */
    $first = Gherkish::docs();

    /** @And second content */
    $second = Gherkish::docs();

    /** @When the second step is selected */
    $selected = Gherkish::docs(step: 'second content', scenario: 'Select an explicit step');

    /** @Then explicit lookup returns its content */
    expect($selected)->toBe($second)->toBe('second')->and($first)->toBe('first');
});

describe('shared arguments', function () {
    beforeEach(function () {
        /** @Given shared users */
        $this->sharedUsers = Gherkish::dataTable();
    });

    it('Read shared setup', function () {
        /** @Given shared data is available */
        expect($this->sharedUsers)->not->toBeEmpty();

        /** @When shared data is used */
        $users = $this->sharedUsers;

        /** @Then the setup table is available */
        expect($users)->toBe([['name' => 'Ada']]);
    });

    it('Reuse shared setup', function () {
        /** @Given shared data is available */
        expect($this->sharedUsers)->not->toBeEmpty();

        /** @When shared data is reused */
        $users = $this->sharedUsers;

        /** @Then the same source table is available */
        expect($users)->toBe([['name' => 'Ada']]);
    });
});
