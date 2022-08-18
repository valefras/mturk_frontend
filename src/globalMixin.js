import axios from 'axios'

const globalMixin = {
  methods: {
    API() {
      return axios.create({
        baseURL: 'https://dh-server.fbk.eu/mturk_frontend/api/',
        headers: {
          'Session-Id': localStorage.getItem('session_id'),
        },
      })
    },
  },
}

export default globalMixin
