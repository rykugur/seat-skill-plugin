{
  description = "fside/seat-skill-notifications — SeAT skill-completion Discord notifications";

  # NOTE: Nix is a *convenience* for contributors who already use it. It is NOT
  # required to build, test, or run this plugin. Production runs on Ubuntu with
  # the PHP/Composer that ships with the SeAT v5 stack. This devshell simply
  # pins a matching PHP + Composer for local development.
  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
    flake-parts.url = "github:hercules-ci/flake-parts";
  };

  outputs = inputs@{ flake-parts, ... }:
    flake-parts.lib.mkFlake { inherit inputs; } {
      systems = [ "x86_64-linux" "aarch64-linux" "x86_64-darwin" "aarch64-darwin" ];

      perSystem = { pkgs, ... }:
        let
          # PHP 8.2 (matches SeAT v5's supported runtime) with the extensions
          # Laravel/Testbench/PHPUnit need, including sqlite for in-memory tests.
          php = pkgs.php82.buildEnv {
            extensions = { enabled, all }:
              enabled ++ (with all; [ pdo_sqlite sqlite3 bcmath ]);
            extraConfig = ''
              memory_limit = 512M
            '';
          };
        in {
          devShells.default = pkgs.mkShell {
            packages = [
              php
              php.packages.composer
            ];

            shellHook = ''
              echo "fside seat-skill-notifications devshell"
              echo "  php:      $(php -r 'echo PHP_VERSION;')"
              echo "  composer: $(composer --version 2>/dev/null | head -n1)"
            '';
          };
        };
    };
}
