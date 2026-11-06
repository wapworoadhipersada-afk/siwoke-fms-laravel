// Wayfinder types a numeric route key (a role's id) differently depending on which tool generated the file:
// the artisan command reads the database column (number), the Vite plugin does not (string). The server accepts
// both, so the value is passed through untyped instead of fighting the generator.
export const routeArg = (value: string | number): never => value as never;
