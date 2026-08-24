<?php
interface MiddlewareInterface
{
    /**
     * @return AppResponse|null|true
     */
    public function handle(AppRequest $req);
}
