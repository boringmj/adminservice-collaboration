<?php

abstract class A {
    abstract public function get();
}

final class B extends A {
    public function get() {
        return 'B';
    }
}

final class C extends A {
    public function get() {
        return 'C';
    }
}

final class D {
    public function get(A $a) {
        return $a->get();
    }
}

$d=new D();
echo $d->get(new B());
echo $d->get(new C());

?>