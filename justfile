check *args:
    vendor/bin/testbench gherkish:check --dir="{{justfile_directory()}}/tests" {{args}}

test:
    composer test
