<?php

namespace AdminService;

use bash\Route as BashRoute;

class Route extends BashRoute {
    public function load() {
        print_r($this->uri);
    }
}