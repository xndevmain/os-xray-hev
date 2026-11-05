#!/usr/local/bin/python3
"""Validate both complete downloads before publishing a new release."""
import os
import sys
from geodata import categories, validate_database

if __name__ == '__main__':
    for kind in ('geosite', 'geoip'):
        validate_database(categories(os.path.join(sys.argv[1], kind + '.dat')), kind)
    print('Geodata validated')
