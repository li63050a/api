<?php
interface HandlerInterface
{
    public function handle(AppRequest $req): void;
}
