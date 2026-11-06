<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\IAM\Http\Web\Controllers\NavigationController;
use Modules\IAM\Http\Web\Controllers\RoleController;
use Modules\IAM\Http\Web\Controllers\UserController;
use Modules\IAM\Http\Web\Controllers\UserPhotoController;

Route::middleware(['web', 'auth', 'verified'])->prefix('iam')->name('iam.')->group(function () {
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->whereNumber('role')->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('roles.destroy');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{managedUser}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{managedUser}', [UserController::class, 'update'])->name('users.update');
    Route::get('users/{managedUser}/photo', [UserPhotoController::class, 'show'])->name('users.photo.show');
    Route::post('users/{managedUser}/photo', [UserPhotoController::class, 'update'])->middleware('throttle:20,1')->name('users.photo.update');
    Route::delete('users/{managedUser}/photo', [UserPhotoController::class, 'destroy'])->name('users.photo.destroy');
    Route::post('users/{managedUser}/reset-password', [UserController::class, 'resetPassword'])->middleware('throttle:10,1')->name('users.reset-password');

    Route::get('navigation', [NavigationController::class, 'index'])->name('navigation.index');
    Route::post('navigation/sync', [NavigationController::class, 'sync'])->name('navigation.sync');
    Route::put('navigation/roles/{role}', [NavigationController::class, 'syncRole'])->whereNumber('role')->name('navigation.role');
    Route::put('navigation/menus/{menu}', [NavigationController::class, 'update'])->whereNumber('menu')->name('navigation.update');
    Route::post('navigation/menus/{menu}/move', [NavigationController::class, 'move'])->whereNumber('menu')->name('navigation.move');
});
