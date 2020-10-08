module.exports = {
    purge: ['./src//*.html', './src//*.vue'],
    theme: {
        maxHeight: {
            '0': '0',
            '1/4': '25%',
            '1/2': '50%',
            '3/4': '75%',
            full: '100%',
            '80': '80%',
        },
        minWidth: {
            '0': '0',
            '1/4': '25%',
            '1/2': '50%',
            '3/4': '75%',
            '5/6': '83.333333%',
        },
        screens: {
            xs: '270px',
            xs2: '400px',
            sm: '640px',
            md: '768px',
            lg: '1024px',
            xl: '1280px',
        },
        extend: {
            transitionProperty: {
                height: 'height',
            },
            colors: {
                primary: '#0068b4',
                primaryDark: '#003e84',
                secondary: '#a5a5a5',
                // ...
            },
            opacity: {
                '10': '.1',
                '20': '.2',
                '30': '.3',
                '40': '.4',
                '50': '.5',
                '60': '.6',
                '70': '.7',
                '80': '.8',
                '85': '.85',
                '90': '.9',
            },
        },
    },
}
